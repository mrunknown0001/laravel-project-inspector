<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Composer\ComposerAudit;
use LaraInspect\Composer\ComposerConfigChecks;
use LaraInspect\Composer\ComposerProject;
use LaraInspect\Composer\LockedPackage;
use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\ResultStatus;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Support\Arr;
use LaraInspect\Support\Evidence;
use LaraInspect\Support\Versions;

/**
 * Composer dependencies: what is required, what is locked, what is installed,
 * and what is outdated, abandoned, vulnerable or incompatible.
 *
 * Network checks go through {@see ComposerAudit}, which never installs or updates anything.
 */
final class DependencyAnalyzer implements AnalyzerInterface
{
    public function name(): string
    {
        return 'dependencies';
    }

    public function title(): string
    {
        return 'Dependencies';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $composer = $context->composer();

        if (!$composer->hasManifest()) {
            $reason = $composer->errors()['composer.json'] ?? 'composer.json was not found.';
            $findings = isset($composer->errors()['composer.json']) ? [new Finding(
                'deps.invalid-manifest',
                'composer.json could not be parsed',
                Severity::High,
                Confidence::Confirmed,
                'composer.json',
                'Composer cannot install or update dependencies while composer.json is invalid.',
                $reason,
                'Fix the JSON syntax, then run "composer validate" in the project.',
            )] : [];

            return new AnalyzerResult($this->name(), $this->title(), ResultStatus::Skipped, findings: $findings, notes: [$reason]);
        }

        $findings = [];
        $notes = [];
        $tables = [];

        $direct = $composer->directPackages();
        $directDev = $composer->directPackages(true);
        $locked = $composer->lockedPackages();
        $lockedProd = array_filter($locked, static fn (LockedPackage $p): bool => !$p->dev);

        $facts = [
            new Fact('Direct dependencies', (string) count($direct), Source::Detected, 'composer.json require'),
            new Fact('Direct development dependencies', (string) count($directDev), Source::Detected, 'composer.json require-dev'),
            new Fact('composer.lock', $composer->hasLock() ? 'Present' : 'Missing', Source::Detected, 'composer.lock'),
        ];
        if ($composer->hasLock()) {
            $facts[] = new Fact('Locked packages', sprintf('%d (%d production, %d development)', count($locked), count($lockedProd), count($locked) - count($lockedProd)), Source::Detected, 'composer.lock');
        }
        $facts[] = new Fact(
            'Installed in vendor/',
            $composer->hasInstalledMetadata() ? sprintf('%d packages', count($composer->installedPackages())) : 'Not installed',
            Source::Detected,
            'vendor/composer/installed.json',
        );
        $facts[] = new Fact('Minimum stability', Arr::string($composer->manifest(), 'minimum-stability', 'stable'), Source::Detected, 'composer.json');

        $platform = $composer->platformRequirements();
        if ($platform !== []) {
            $tables[] = new Table(
                'Platform requirements',
                ['Requirement', 'Constraint'],
                array_map(static fn (string $n, string $c): array => [$n, $c], array_keys($platform), array_values($platform)),
            );
        }

        $tables[] = $this->directDependencyTable($composer, $direct, $directDev);

        foreach ($composer->errors() as $file => $error) {
            if ($file === 'composer.lock') {
                $findings[] = new Finding(
                    'deps.invalid-lock',
                    'composer.lock could not be parsed',
                    Severity::High,
                    Confidence::Confirmed,
                    'composer.lock',
                    'An unreadable lock file means installs are not reproducible and dependency checks cannot run.',
                    $error,
                    'Regenerate the lock file with "composer update --lock" after reviewing why it was corrupted.',
                );
            }
        }

        if (!$composer->hasLock() && !isset($composer->errors()['composer.lock'])) {
            $findings[] = new Finding(
                'deps.lock-missing',
                'composer.lock is missing',
                Severity::Medium,
                Confidence::Confirmed,
                'composer.lock',
                'Without a lock file every install may resolve different package versions, so builds are not reproducible and audits cannot know what is deployed.',
                'No composer.lock in the project root.',
                'Run "composer update" once, review the result, and commit composer.lock for applications.',
            );
        }

        if ($composer->hasLock()) {
            array_push($findings, ...$this->lockDriftFindings($composer, $direct + $directDev));
            array_push($findings, ...$this->phpCompatibilityFindings($composer));
            array_push($findings, ...$this->installedDriftFindings($composer));
        }

        array_push($findings, ...ComposerConfigChecks::findings($composer));

        $abandoned = $this->abandonedFromLock($composer);

        // Network checks: advisories and outdated packages (cached, shared with the security analyzer).
        $audit = $context->composerAudit();
        array_push($notes, ...$audit->notes);
        $advisories = $audit->advisories;
        $outdated = $audit->outdated;
        foreach ($audit->abandoned as $name => $replacement) {
            $abandoned[$name] ??= $replacement;
        }

        array_push($findings, ...ComposerAudit::advisoryFindings($composer, $advisories));
        array_push($findings, ...$this->abandonedFindings($composer, $abandoned));

        if ($advisories !== []) {
            $rows = [];
            foreach ($advisories as $package => $list) {
                foreach ($list as $advisory) {
                    $rows[] = [
                        $package,
                        Arr::string($advisory, 'cve') ?: Arr::string($advisory, 'advisoryId'),
                        strtoupper(Arr::string($advisory, 'severity', 'unknown')),
                        Arr::string($advisory, 'affectedVersions'),
                        Arr::string($advisory, 'title'),
                    ];
                }
            }
            $tables[] = new Table('Security advisories', ['Package', 'Advisory', 'Severity', 'Affected versions', 'Title'], $rows);
        }

        if ($outdated !== []) {
            $tables[] = new Table(
                'Outdated direct dependencies',
                ['Package', 'Locked', 'Latest', 'Update type'],
                array_map(static fn (array $o): array => [
                    Arr::string($o, 'name'),
                    Arr::string($o, 'version'),
                    Arr::string($o, 'latest'),
                    Arr::string($o, 'latest-status') === 'semver-safe-update' ? 'compatible' : 'major',
                ], $outdated),
            );
            $finding = $this->outdatedFinding($outdated);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: $findings,
            notes: $notes,
            data: [
                'require' => $composer->require(),
                'require_dev' => $composer->requireDev(),
                'locked' => array_map(static fn (LockedPackage $p): array => ['version' => $p->version, 'dev' => $p->dev], $locked),
                'abandoned' => $abandoned,
                'advisory_count' => array_sum(array_map('count', $advisories)),
                'outdated' => array_map(static fn (array $o): array => [
                    'name' => Arr::string($o, 'name'),
                    'version' => Arr::string($o, 'version'),
                    'latest' => Arr::string($o, 'latest'),
                    'status' => Arr::string($o, 'latest-status'),
                ], $outdated),
            ],
        );
    }

    /**
     * @param array<string, string> $direct
     * @param array<string, string> $directDev
     */
    private function directDependencyTable(ComposerProject $composer, array $direct, array $directDev): Table
    {
        $rows = [];
        foreach ([[$direct, 'production'], [$directDev, 'development']] as [$packages, $scope]) {
            foreach ($packages as $name => $constraint) {
                $locked = $composer->lockedProviderOf($name);
                $rows[] = [
                    $name,
                    $constraint,
                    $locked === null ? ($composer->hasLock() ? 'not locked' : '-') : $locked->version,
                    $scope,
                ];
            }
        }

        return new Table('Direct dependencies', ['Package', 'Constraint', 'Locked version', 'Scope'], $rows);
    }

    /**
     * @param array<string, string> $requirements
     * @return list<Finding>
     */
    private function lockDriftFindings(ComposerProject $composer, array $requirements): array
    {
        $missing = [];
        $mismatched = [];
        foreach ($requirements as $name => $constraint) {
            $locked = $composer->lockedProviderOf($name);
            if ($locked === null) {
                $missing[] = sprintf('%s (%s)', $name, $constraint);
                continue;
            }
            // Packages satisfied through replace/provide do not carry the required version.
            if (strtolower($locked->name) !== strtolower($name)) {
                continue;
            }
            if (Versions::satisfies($locked->version, $constraint) === false) {
                $mismatched[] = sprintf('%s: requires "%s", locked %s', $name, $constraint, $locked->version);
            }
        }

        $findings = [];
        if ($missing !== []) {
            $findings[] = new Finding(
                'deps.lock-missing-package',
                sprintf('%d required package%s missing from composer.lock', count($missing), count($missing) === 1 ? ' is' : 's are'),
                Severity::Medium,
                Confidence::Confirmed,
                'composer.lock',
                'composer.json requires packages that the lock file does not contain, so the lock file is out of date. "composer install" will warn and install an incomplete set.',
                Evidence::list($missing),
                'Run "composer update <package>" for the listed packages in the project, review the diff, and commit the updated composer.lock.',
            );
        }
        if ($mismatched !== []) {
            $findings[] = new Finding(
                'deps.lock-constraint-mismatch',
                sprintf('%d locked version%s no longer match%s composer.json', count($mismatched), count($mismatched) === 1 ? '' : 's', count($mismatched) === 1 ? 'es' : ''),
                Severity::Medium,
                Confidence::Confirmed,
                'composer.lock',
                'The constraints in composer.json were changed after the lock file was generated, so the installed versions are not the ones declared.',
                Evidence::list($mismatched),
                'Run "composer update" for the affected packages and commit composer.lock, or revert the constraint change.',
            );
        }

        return $findings;
    }

    /**
     * Locked packages whose PHP requirement conflicts with the project's PHP range or platform pin.
     *
     * @return list<Finding>
     */
    private function phpCompatibilityFindings(ComposerProject $composer): array
    {
        $override = $composer->platformPhpOverride();
        $projectConstraint = $composer->phpConstraint();
        if ($override === null && $projectConstraint === null) {
            return [];
        }

        $impossible = [];
        $partial = [];
        foreach ($composer->lockedPackages() as $package) {
            $requirement = $package->phpRequirement();
            if ($requirement === null) {
                continue;
            }
            $label = sprintf('%s %s requires php "%s"', $package->name, $package->version, $requirement);

            if ($override !== null) {
                if (Versions::satisfies($override, $requirement) === false) {
                    $impossible[] = $label;
                }
                continue;
            }

            /** @var string $projectConstraint */
            if (Versions::intersects($projectConstraint, $requirement) === false) {
                $impossible[] = $label;
            } elseif (!$package->dev && Versions::isSubsetOf($projectConstraint, $requirement) === false) {
                $partial[] = $label;
            }
        }

        $target = $override !== null
            ? sprintf('the platform PHP pinned in composer.json (%s)', $override)
            : sprintf('the project PHP range "%s"', $projectConstraint);

        $findings = [];
        if ($impossible !== []) {
            $findings[] = new Finding(
                'deps.php-incompatible',
                sprintf('%d locked package%s cannot run on %s', count($impossible), count($impossible) === 1 ? '' : 's', $override !== null ? 'the pinned PHP version' : 'any PHP version the project allows'),
                Severity::High,
                Confidence::Confirmed,
                'composer.lock',
                sprintf('These packages need a PHP version outside %s. Installing on a matching runtime fails the platform check, or the code fails at runtime.', $target),
                Evidence::list($impossible),
                'Align the "php" requirement (or config.platform.php) with the runtime actually used, then run "composer update" to re-resolve the lock file.',
            );
        }
        if ($partial !== []) {
            $findings[] = new Finding(
                'deps.php-range-too-wide',
                sprintf('The PHP range allows versions that %d locked package%s not support', count($partial), count($partial) === 1 ? ' does' : 's do'),
                Severity::Medium,
                Confidence::Potential,
                'composer.json',
                sprintf('%s includes PHP versions older or newer than some locked packages accept. Installs on those versions fail even though composer.json claims they are supported.', ucfirst($target)),
                Evidence::list($partial),
                'Narrow the "php" requirement in composer.json to the range all locked packages support (or pin config.platform.php to the production version).',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function installedDriftFindings(ComposerProject $composer): array
    {
        if (!$composer->hasInstalledMetadata()) {
            return [];
        }
        $installed = $composer->installedPackages();
        $drift = [];
        foreach ($composer->lockedPackages() as $name => $package) {
            $actual = $installed[$name] ?? null;
            if ($actual === null) {
                if (!$package->dev) {
                    $drift[] = sprintf('%s %s is locked but not installed', $package->name, $package->version);
                }
            } elseif (Versions::clean($actual->version) !== Versions::clean($package->version)) {
                $drift[] = sprintf('%s: locked %s, installed %s', $package->name, $package->version, $actual->version);
            }
        }
        if ($drift === []) {
            return [];
        }

        return [new Finding(
            'deps.installed-drift',
            'Installed packages do not match composer.lock',
            Severity::Low,
            Confidence::Confirmed,
            'vendor/composer/installed.json',
            'The vendor directory was installed from a different lock file. Local behaviour may differ from what is deployed.',
            Evidence::list($drift),
            'Run "composer install" in the project to sync vendor/ with composer.lock.',
        )];
    }

    /**
     * @return array<string, string|true> package => replacement or true
     */
    private function abandonedFromLock(ComposerProject $composer): array
    {
        $abandoned = [];
        foreach ($composer->lockedPackages() as $package) {
            if ($package->isAbandoned()) {
                $abandoned[$package->name] = $package->replacement() ?? true;
            }
        }

        return $abandoned;
    }

    /**
     * @param array<string, string|true> $abandoned
     * @return list<Finding>
     */
    private function abandonedFindings(ComposerProject $composer, array $abandoned): array
    {
        $findings = [];
        foreach ($abandoned as $name => $replacement) {
            $package = $composer->lockedPackage($name);
            $dev = $package !== null && $package->dev;
            $findings[] = new Finding(
                'deps.abandoned',
                sprintf('%s is abandoned', $name),
                $dev ? Severity::Info : Severity::Low,
                Confidence::Confirmed,
                'composer.lock: ' . $name,
                sprintf('The maintainers have marked this %spackage as abandoned. It will not receive bug or security fixes.', $dev ? 'development ' : ''),
                sprintf('%s %s is marked abandoned%s.', $name, $package->version ?? '', is_string($replacement) ? sprintf('; suggested replacement: %s', $replacement) : ''),
                is_string($replacement)
                    ? sprintf('Migrate from %s to %s.', $name, $replacement)
                    : sprintf('Find a maintained alternative to %s, or plan to remove it.', $name),
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $outdated
     */
    private function outdatedFinding(array $outdated): ?Finding
    {
        $major = array_values(array_filter($outdated, static fn (array $o): bool => Arr::string($o, 'latest-status') === 'update-possible'));
        if ($major === []) {
            return null;
        }

        return new Finding(
            'deps.outdated-major',
            sprintf('%d direct dependenc%s behind a major release', count($major), count($major) === 1 ? 'y is' : 'ies are'),
            Severity::Info,
            Confidence::Confirmed,
            'composer.json',
            'Newer major versions exist. Staying far behind makes future upgrades and security patches harder. Major updates may include breaking changes.',
            Evidence::list(array_map(
                static fn (array $o): string => sprintf('%s %s -> %s', Arr::string($o, 'name'), Arr::string($o, 'version'), Arr::string($o, 'latest')),
                $major,
            )),
            'Review the changelogs and upgrade guides, then update one package at a time.',
        );
    }
}
