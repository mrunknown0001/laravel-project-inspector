<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

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
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Support\Arr;
use LaraInspect\Support\Versions;

/**
 * Composer dependencies: what is required, what is locked, what is installed,
 * and what is outdated, abandoned, vulnerable or incompatible.
 *
 * Composer is only ever invoked with --locked --no-plugins --no-scripts, so it
 * reads composer.lock and never loads code from the project's vendor directory.
 * It is never asked to install or update anything.
 */
final class DependencyAnalyzer implements AnalyzerInterface
{
    /** Composer flags that keep project code (plugins, scripts) from running. */
    private const SAFE_COMPOSER_FLAGS = ['--locked', '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi'];

    private const MAX_EVIDENCE_ITEMS = 15;

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

        array_push($findings, ...$this->insecureConfigurationFindings($composer));

        $abandoned = $this->abandonedFromLock($composer);

        // Network checks: advisories and outdated packages.
        $advisories = [];
        $outdated = [];
        if (!$composer->hasLock()) {
            $notes[] = 'Security advisories and outdated packages were not checked because composer.lock is missing.';
        } elseif ($context->options->offline) {
            $notes[] = 'Offline mode: security advisories and outdated packages were not checked.';
        } elseif (!$context->processes->isAvailable('composer')) {
            $notes[] = 'Composer was not found on PATH: security advisories and outdated packages were not checked.';
        } else {
            [$advisories, $auditAbandoned, $auditNote] = $this->runAudit($context);
            if ($auditNote !== null) {
                $notes[] = $auditNote;
            }
            foreach ($auditAbandoned as $name => $replacement) {
                $abandoned[$name] ??= $replacement;
            }

            [$outdated, $outdatedNote] = $this->runOutdated($context);
            if ($outdatedNote !== null) {
                $notes[] = $outdatedNote;
            }
        }

        array_push($findings, ...$this->advisoryFindings($composer, $advisories));
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
                self::evidenceList($missing),
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
                self::evidenceList($mismatched),
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
                self::evidenceList($impossible),
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
                self::evidenceList($partial),
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
            self::evidenceList($drift),
            'Run "composer install" in the project to sync vendor/ with composer.lock.',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function insecureConfigurationFindings(ComposerProject $composer): array
    {
        $config = $composer->config();
        $manifest = $composer->manifest();
        $findings = [];

        if (($config['disable-tls'] ?? false) === true) {
            $findings[] = new Finding(
                'deps.tls-disabled',
                'Composer TLS verification is disabled',
                Severity::High,
                Confidence::Confirmed,
                'composer.json',
                'With "disable-tls" enabled, package downloads can be intercepted and replaced in transit.',
                '"config": {"disable-tls": true}',
                'Remove "disable-tls" from composer.json and fix the underlying certificate problem instead.',
            );
        }
        if (($config['secure-http'] ?? true) === false) {
            $findings[] = new Finding(
                'deps.insecure-http',
                'Composer allows plain HTTP downloads',
                Severity::High,
                Confidence::Confirmed,
                'composer.json',
                'With "secure-http" disabled, packages may be fetched over unencrypted HTTP and tampered with.',
                '"config": {"secure-http": false}',
                'Remove "secure-http": false and serve private repositories over HTTPS.',
            );
        }

        $insecureRepositories = [];
        foreach (Arr::array($manifest, 'repositories') as $key => $repository) {
            if (is_array($repository) && str_starts_with(strtolower(Arr::string($repository, 'url')), 'http://')) {
                $insecureRepositories[] = sprintf('%s: %s', is_string($key) ? $key : Arr::string($repository, 'type', 'repository'), Arr::string($repository, 'url'));
            }
        }
        if ($insecureRepositories !== []) {
            $findings[] = new Finding(
                'deps.http-repository',
                'A Composer repository uses plain HTTP',
                Severity::Medium,
                Confidence::Confirmed,
                'composer.json',
                'Packages from HTTP repositories can be modified in transit.',
                self::evidenceList($insecureRepositories),
                'Switch the repository URL to HTTPS.',
            );
        }

        $stability = strtolower(Arr::string($manifest, 'minimum-stability', 'stable'));
        if (in_array($stability, ['dev', 'alpha', 'beta', 'rc'], true) && Arr::bool($manifest, 'prefer-stable') !== true) {
            $findings[] = new Finding(
                'deps.unstable-minimum-stability',
                sprintf('minimum-stability is "%s" without prefer-stable', $stability),
                Severity::Low,
                Confidence::Potential,
                'composer.json',
                'Composer may resolve pre-release or development versions of any dependency, which receive less testing and security review.',
                sprintf('"minimum-stability": "%s", "prefer-stable" not set to true.', $stability),
                'Set "prefer-stable": true, or use per-package stability flags (e.g. "vendor/pkg": "^1.0@beta") instead.',
            );
        }

        if (($config['allow-plugins'] ?? null) === true) {
            $findings[] = new Finding(
                'deps.all-plugins-allowed',
                'All Composer plugins are allowed to run',
                Severity::Low,
                Confidence::Potential,
                'composer.json',
                'Composer plugins execute code during install and update. Allowing every plugin means a compromised or newly added dependency can run code without review.',
                '"config": {"allow-plugins": true}',
                'Replace true with an explicit map of trusted plugins, e.g. {"pestphp/pest-plugin": true}.',
            );
        }

        $ignored = Arr::array(Arr::array($config, 'audit'), 'ignore');
        if ($ignored !== []) {
            $findings[] = new Finding(
                'deps.audit-ignores',
                sprintf('%d security advisor%s ignored in composer.json', count($ignored), count($ignored) === 1 ? 'y is' : 'ies are'),
                Severity::Info,
                Confidence::Confirmed,
                'composer.json',
                'Ignored advisories are hidden from "composer audit". Make sure each ignore is still justified.',
                self::evidenceList(array_map(
                    static fn (int|string $k, mixed $v): string => is_string($k) ? sprintf('%s: %s', $k, is_scalar($v) ? (string) $v : 'ignored') : (is_scalar($v) ? (string) $v : ''),
                    array_keys($ignored),
                    array_values($ignored),
                )),
                'Review the ignore list in config.audit.ignore and remove entries that no longer apply.',
            );
        }

        return $findings;
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
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, string|true>, 2: string|null}
     */
    private function runAudit(InspectionContext $context): array
    {
        $result = $context->processes->run(new CommandSpec(
            'composer',
            ['audit', '--format=json', '--abandoned=report', ...self::SAFE_COMPOSER_FLAGS, '--working-dir=' . $context->root()],
            'Check composer.lock against the Packagist security advisory database',
            sys_get_temp_dir(),
            $context->options->networkTimeout,
            network: true,
        ));

        // composer audit exits non-zero when it finds advisories; the JSON is still valid.
        $json = $this->decodeComposerJson($result);
        if ($json === null) {
            return [[], [], 'Security advisory check failed: ' . $result->failureReason() . '.'];
        }

        $advisories = [];
        $raw = Arr::array($json, 'advisories');
        foreach ($raw as $package => $list) {
            if (!is_array($list)) {
                continue;
            }
            /** @var list<array<string, mixed>> $entries */
            $entries = array_values(array_filter($list, 'is_array'));
            if ($entries !== []) {
                $advisories[(string) $package] = $entries;
            }
        }

        $abandoned = [];
        foreach (Arr::array($json, 'abandoned') as $package => $replacement) {
            $abandoned[(string) $package] = is_string($replacement) && $replacement !== '' ? $replacement : true;
        }

        return [$advisories, $abandoned, null];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function runOutdated(InspectionContext $context): array
    {
        $result = $context->processes->run(new CommandSpec(
            'composer',
            ['outdated', '--direct', '--format=json', ...self::SAFE_COMPOSER_FLAGS, '--working-dir=' . $context->root()],
            'List direct dependencies with newer releases on Packagist',
            sys_get_temp_dir(),
            $context->options->networkTimeout,
            network: true,
        ));

        $json = $this->decodeComposerJson($result);
        if ($json === null) {
            return [[], 'Outdated package check failed: ' . $result->failureReason() . '.'];
        }

        // With --locked Composer reports under "locked"; without it, "installed".
        $entries = Arr::listOfArrays($json, 'locked') ?: Arr::listOfArrays($json, 'installed');
        $outdated = array_values(array_filter(
            $entries,
            static fn (array $e): bool => in_array(Arr::string($e, 'latest-status'), ['semver-safe-update', 'update-possible'], true),
        ));

        return [$outdated, null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeComposerJson(ProcessResult $result): ?array
    {
        if (!$result->ran()) {
            return null;
        }
        // Composer may print warnings before the JSON document; start at the first brace.
        $start = strpos($result->output, '{');
        if ($start === false) {
            return null;
        }
        try {
            $decoded = json_decode(substr($result->output, $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $advisories
     * @return list<Finding>
     */
    private function advisoryFindings(ComposerProject $composer, array $advisories): array
    {
        $findings = [];
        foreach ($advisories as $name => $list) {
            $severity = Severity::Low;
            $lines = [];
            $ranges = [];
            foreach ($list as $advisory) {
                $advisorySeverity = Severity::fromAdvisory(Arr::string($advisory, 'severity') ?: null);
                if ($advisorySeverity->weight() > $severity->weight()) {
                    $severity = $advisorySeverity;
                }
                $lines[] = sprintf(
                    '%s (%s): %s; affected %s%s',
                    Arr::string($advisory, 'cve') ?: Arr::string($advisory, 'advisoryId'),
                    Arr::string($advisory, 'severity', 'severity unknown'),
                    Arr::string($advisory, 'title'),
                    Arr::string($advisory, 'affectedVersions'),
                    Arr::string($advisory, 'link') !== '' ? ' (' . Arr::string($advisory, 'link') . ')' : '',
                );
                $ranges[] = Arr::string($advisory, 'affectedVersions');
            }
            $package = $composer->lockedPackage($name);
            $dev = $package !== null && $package->dev;

            $findings[] = new Finding(
                'deps.security-advisory',
                sprintf('%s %s has %d known security advisor%s', $name, $package->version ?? '', count($list), count($list) === 1 ? 'y' : 'ies'),
                $severity,
                Confidence::Confirmed,
                'composer.lock: ' . $name,
                sprintf(
                    'The locked version falls within the affected range of published advisories.%s Whether the application is exploitable depends on how the package is used.',
                    $dev ? ' This is a development dependency, which normally is not deployed to production.' : '',
                ),
                self::evidenceList($lines),
                sprintf(
                    'Update %s to a version outside the affected ranges (%s), e.g. "composer update %s" in the project, then run the test suite.',
                    $name,
                    implode('; ', array_unique(array_filter($ranges))),
                    $name,
                ),
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
            self::evidenceList(array_map(
                static fn (array $o): string => sprintf('%s %s -> %s', Arr::string($o, 'name'), Arr::string($o, 'version'), Arr::string($o, 'latest')),
                $major,
            )),
            'Review the changelogs and upgrade guides, then update one package at a time.',
        );
    }

    /**
     * @param list<string> $items
     */
    private static function evidenceList(array $items): string
    {
        $shown = array_slice($items, 0, self::MAX_EVIDENCE_ITEMS);
        $text = '- ' . implode("\n- ", $shown);
        if (count($items) > count($shown)) {
            $text .= sprintf("\n- ... and %d more", count($items) - count($shown));
        }

        return $text;
    }
}
