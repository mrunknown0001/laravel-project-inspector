<?php

declare(strict_types=1);

namespace LaraInspect\Composer;

use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Process\ProcessRunnerInterface;
use LaraInspect\Support\Arr;
use LaraInspect\Support\Evidence;

/**
 * Runs "composer audit" and "composer outdated" against composer.lock.
 *
 * Composer is only ever invoked with --locked --no-plugins --no-scripts, so it
 * reads composer.lock and never loads code from the project's vendor directory.
 * It is never asked to install or update anything.
 */
final class ComposerAudit
{
    /** Composer flags that keep project code (plugins, scripts) from running. */
    public const SAFE_FLAGS = ['--locked', '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi'];

    public function __construct(
        private readonly ProcessRunnerInterface $processes,
        private readonly string $root,
        private readonly bool $offline,
        private readonly int $timeout,
    ) {
    }

    public function run(ComposerProject $composer): ComposerAuditResult
    {
        if (!$composer->hasLock()) {
            return ComposerAuditResult::skipped('Security advisories and outdated packages were not checked because composer.lock is missing.');
        }
        if ($this->offline) {
            return ComposerAuditResult::skipped('Offline mode: security advisories and outdated packages were not checked.');
        }
        if (!$this->processes->isAvailable('composer')) {
            return ComposerAuditResult::skipped('Composer was not found on PATH: security advisories and outdated packages were not checked.');
        }

        $notes = [];
        [$advisories, $abandoned, $auditNote] = $this->audit();
        if ($auditNote !== null) {
            $notes[] = $auditNote;
        }
        [$outdated, $outdatedNote] = $this->outdated();
        if ($outdatedNote !== null) {
            $notes[] = $outdatedNote;
        }

        return new ComposerAuditResult(true, $advisories, $abandoned, $outdated, $notes);
    }

    /**
     * One finding per vulnerable package, with the highest advisory severity.
     *
     * @param array<string, list<array<string, mixed>>> $advisories
     * @return list<Finding>
     */
    public static function advisoryFindings(ComposerProject $composer, array $advisories): array
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
                Evidence::list($lines),
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
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, string|true>, 2: string|null}
     */
    private function audit(): array
    {
        $result = $this->processes->run(new CommandSpec(
            'composer',
            ['audit', '--format=json', '--abandoned=report', ...self::SAFE_FLAGS, '--working-dir=' . $this->root],
            'Check composer.lock against the Packagist security advisory database',
            sys_get_temp_dir(),
            $this->timeout,
            network: true,
        ));

        // composer audit exits non-zero when it finds advisories; the JSON is still valid.
        $json = self::decode($result);
        if ($json === null) {
            return [[], [], 'Security advisory check failed: ' . $result->failureReason() . '.'];
        }

        $advisories = [];
        foreach (Arr::array($json, 'advisories') as $package => $list) {
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
    private function outdated(): array
    {
        $result = $this->processes->run(new CommandSpec(
            'composer',
            ['outdated', '--direct', '--format=json', ...self::SAFE_FLAGS, '--working-dir=' . $this->root],
            'List direct dependencies with newer releases on Packagist',
            sys_get_temp_dir(),
            $this->timeout,
            network: true,
        ));

        $json = self::decode($result);
        if ($json === null) {
            return [[], 'Outdated package check failed: ' . $result->failureReason() . '.'];
        }

        // With --locked Composer reports under "locked"; without it, "installed".
        $entries = Arr::listOfArrays($json, 'locked') ?: Arr::listOfArrays($json, 'installed');

        return [array_values(array_filter(
            $entries,
            static fn (array $e): bool => in_array(Arr::string($e, 'latest-status'), ['semver-safe-update', 'update-possible'], true),
        )), null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(ProcessResult $result): ?array
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
}
