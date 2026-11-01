<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\DependencyAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\ResultStatus;
use LaraInspect\Model\Severity;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class DependencyAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * Responses captured from real "composer audit/outdated --locked --format=json" runs.
     */
    private function onlineRunner(): FakeProcessRunner
    {
        return (new FakeProcessRunner())
            ->respond('composer audit', ProcessResult::completed(1, Fixtures::json('composer/audit-with-advisories.json')))
            ->respond('composer outdated', ProcessResult::completed(0, Fixtures::json('composer/outdated-locked.json')));
    }

    private function analyze(string $root, ?FakeProcessRunner $runner = null, bool $offline = false): AnalyzerResult
    {
        return (new DependencyAnalyzer())->analyze(Fixtures::context($root, $runner ?? new FakeProcessRunner(), $offline));
    }

    /**
     * @return array<string, Finding>
     */
    private static function byId(AnalyzerResult $result): array
    {
        $map = [];
        foreach ($result->findings as $finding) {
            $map[$finding->id . ($finding->id === 'deps.abandoned' || $finding->id === 'deps.security-advisory' ? ':' . $finding->component : '')] = $finding;
        }

        return $map;
    }

    public function testReportsAbandonedPackagesFromTheLockFile(): void
    {
        $findings = self::byId($this->analyze(Fixtures::project('laravel-8'), offline: true));

        self::assertSame(Severity::Low, $findings['deps.abandoned:composer.lock: swiftmailer/swiftmailer']->severity);
        self::assertStringContainsString('symfony/mailer', $findings['deps.abandoned:composer.lock: swiftmailer/swiftmailer']->remediation);
        self::assertSame(Severity::Low, $findings['deps.abandoned:composer.lock: fruitcake/laravel-cors']->severity);
        self::assertSame(Severity::Info, $findings['deps.abandoned:composer.lock: facade/ignition']->severity, 'Abandoned dev packages are informational.');
    }

    public function testOfflineModeSkipsNetworkChecks(): void
    {
        $runner = $this->onlineRunner();
        $result = $this->analyze(Fixtures::project('laravel-8'), $runner, offline: true);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('Offline mode', implode(' ', $result->notes));
    }

    public function testMissingComposerIsReportedAsANote(): void
    {
        $result = $this->analyze(Fixtures::project('laravel-8'), new FakeProcessRunner());

        self::assertStringContainsString('Composer was not found', implode(' ', $result->notes));
    }

    public function testParsesSecurityAdvisoriesAndOutdatedPackages(): void
    {
        $result = $this->analyze(Fixtures::project('laravel-8'), $this->onlineRunner());
        $findings = self::byId($result);

        $psr7 = $findings['deps.security-advisory:composer.lock: guzzlehttp/psr7'];
        self::assertSame(Severity::Medium, $psr7->severity, 'Severity is the highest of the package advisories.');
        self::assertSame(Confidence::Confirmed, $psr7->confidence);
        self::assertStringContainsString('CVE-2026-59882', $psr7->evidence);
        self::assertStringContainsString('5 known security advisories', $psr7->title);

        // Abandoned status reported by composer audit is merged with the lock file's.
        self::assertArrayHasKey('deps.abandoned:composer.lock: swiftmailer/swiftmailer', $findings);

        self::assertSame(Severity::Info, $findings['deps.outdated-major']->severity);
        $tables = array_map(static fn ($t) => $t->title, $result->tables);
        self::assertContains('Security advisories', $tables);
        self::assertContains('Outdated direct dependencies', $tables);
        self::assertSame(8, $result->data['advisory_count']);
    }

    public function testComposerAlwaysRunsLockedWithoutPluginsOrScripts(): void
    {
        $runner = $this->onlineRunner();
        $this->analyze(Fixtures::project('laravel-8'), $runner);

        self::assertCount(2, $runner->commands);
        foreach ($runner->commands as $command) {
            self::assertSame('composer', $command->executable);
            self::assertContains($command->arguments[0], ['audit', 'outdated']);
            foreach (['--locked', '--no-plugins', '--no-scripts', '--no-interaction'] as $flag) {
                self::assertContains($flag, $command->arguments);
            }
            self::assertContains('--working-dir=' . Fixtures::reader(Fixtures::project('laravel-8'))->root(), $command->arguments);
            self::assertTrue($command->network);
            self::assertFalse($command->sideEffects);
            self::assertSame(sys_get_temp_dir(), $command->workingDirectory);
        }
        foreach ($runner->commandLines() as $line) {
            self::assertDoesNotMatchRegularExpression('/\b(install|update|require|remove)\b/', $line);
        }
    }

    public function testFailedOrTimedOutComposerRunsBecomeNotes(): void
    {
        $runner = (new FakeProcessRunner())
            ->respond('composer audit', new ProcessResult(ProcessResult::OUTCOME_TIMED_OUT))
            ->respond('composer outdated', ProcessResult::completed(1, 'not json', 'Could not reach repo.packagist.org'));

        $result = $this->analyze(Fixtures::project('laravel-8'), $runner);

        $notes = implode(' ', $result->notes);
        self::assertStringContainsString('Security advisory check failed: the command timed out', $notes);
        self::assertStringContainsString('Outdated package check failed', $notes);
        self::assertSame(ResultStatus::Completed, $result->status);
    }

    public function testToleratesWarningsBeforeTheJsonDocument(): void
    {
        $runner = (new FakeProcessRunner())
            ->respond('composer audit', ProcessResult::completed(0, "Warning: something\n" . '{"advisories": [], "abandoned": []}'))
            ->respond('composer outdated', ProcessResult::completed(0, '{"locked": []}'));

        self::assertSame([], array_filter($this->analyze(Fixtures::project('laravel-8'), $runner)->notes, static fn ($n) => str_contains($n, 'failed')));
    }

    public function testDetectsLockDriftAndPhpRangeProblems(): void
    {
        $findings = self::byId($this->analyze(Fixtures::project('laravel-10'), offline: true));

        self::assertStringContainsString('spatie/laravel-permission', $findings['deps.lock-missing-package']->evidence);
        self::assertStringContainsString('laravel/tinker: requires "^2.8", locked v2.7.0', $findings['deps.lock-constraint-mismatch']->evidence);
        self::assertStringContainsString('1 locked version no longer matches', $findings['deps.lock-constraint-mismatch']->title);

        $range = $findings['deps.php-range-too-wide'];
        self::assertSame(Confidence::Potential, $range->confidence);
        self::assertStringContainsString('symfony/string v7.0.4 requires php ">=8.2"', $range->evidence);
        self::assertStringNotContainsString('laravel/pint', $range->evidence, 'Dev packages do not trigger the partial-range rule.');

        $drift = $findings['deps.installed-drift'];
        self::assertStringContainsString('guzzlehttp/guzzle: locked 7.8.1, installed 7.8.0', $drift->evidence);
        self::assertStringContainsString('laravel/tinker v2.7.0 is locked but not installed', $drift->evidence);
    }

    public function testReplacedPackagesAreNotReportedMissing(): void
    {
        $root = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2", "laravel/framework": "^11.0", "illuminate/support": "^11.0"}}',
            'composer.lock' => json_encode(['packages' => [[
                'name' => 'laravel/framework', 'version' => 'v11.0.0', 'require' => ['php' => '^8.2'],
                'replace' => ['illuminate/support' => 'self.version'],
            ]], 'packages-dev' => []], JSON_THROW_ON_ERROR),
        ]);

        self::assertArrayNotHasKey('deps.lock-missing-package', self::byId($this->analyze($root, offline: true)));
    }

    public function testPinnedPlatformPhpIncompatibilityIsHigh(): void
    {
        $finding = self::byId($this->analyze(Fixtures::project('laravel-11'), offline: true))['deps.php-incompatible'];

        self::assertSame(Severity::High, $finding->severity);
        self::assertStringContainsString('symfony/clock v8.0.1 requires php ">=8.4"', $finding->evidence);
        self::assertStringContainsString('8.2.12', $finding->explanation);
    }

    public function testInsecureComposerConfiguration(): void
    {
        $findings = self::byId($this->analyze(Fixtures::project('laravel-12'), offline: true));

        self::assertSame(Severity::Medium, $findings['deps.lock-missing']->severity);
        self::assertSame(Severity::High, $findings['deps.insecure-http']->severity);
        self::assertStringContainsString('http://packages.example.test', $findings['deps.http-repository']->evidence);
        self::assertSame(Confidence::Potential, $findings['deps.unstable-minimum-stability']->confidence);
        self::assertStringContainsString('PKSA-fixture-0001', $findings['deps.audit-ignores']->evidence);
    }

    public function testTlsDisabledAndAllPluginsAllowed(): void
    {
        $root = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"disable-tls": true, "allow-plugins": true}, "repositories": {"private": {"type": "vcs", "url": "http://git.example.test/repo.git"}}}',
        ]);
        $findings = self::byId($this->analyze($root, offline: true));

        self::assertSame(Severity::High, $findings['deps.tls-disabled']->severity);
        self::assertSame(Severity::Low, $findings['deps.all-plugins-allowed']->severity);
        self::assertStringContainsString('private: http://git.example.test/repo.git', $findings['deps.http-repository']->evidence);
    }

    public function testInvalidManifestIsAHighFindingAndSkipsTheRest(): void
    {
        $result = $this->analyze($this->makeProject(['composer.json' => '{"require": ']), offline: true);

        self::assertSame(ResultStatus::Skipped, $result->status);
        self::assertSame('deps.invalid-manifest', $result->findings[0]->id);
        self::assertSame(Severity::High, $result->findings[0]->severity);
    }

    public function testInvalidLockIsReported(): void
    {
        $result = $this->analyze($this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}', 'composer.lock' => 'nope']), offline: true);

        self::assertArrayHasKey('deps.invalid-lock', self::byId($result));
        self::assertArrayNotHasKey('deps.lock-missing', self::byId($result));
    }

    public function testEvidenceListsAreTruncated(): void
    {
        $require = [];
        for ($i = 1; $i <= 20; $i++) {
            $require["acme/package-{$i}"] = '^1.0';
        }
        $root = $this->makeProject([
            'composer.json' => json_encode(['require' => $require], JSON_THROW_ON_ERROR),
            'composer.lock' => '{"packages": [], "packages-dev": []}',
        ]);

        $evidence = self::byId($this->analyze($root, offline: true))['deps.lock-missing-package']->evidence;

        self::assertStringContainsString('... and 5 more', $evidence);
        self::assertSame(16, substr_count($evidence, "\n- ") + 1);
    }
}
