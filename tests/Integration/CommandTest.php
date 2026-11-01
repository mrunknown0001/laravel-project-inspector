<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Integration;

use LaraInspect\Console\Application;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Process\AuditLog;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Support\Services;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the real CLI application end to end, from a temporary working directory.
 */
final class CommandTest extends TestCase
{
    use UsesTemporaryDirectory;

    /** Secret values from the laravel-8 fixture's .env that must never appear in output. */
    private const FIXTURE_SECRETS = [
        'Fixture-DB-Pass-8812',
        'fixture-redis-secret-42',
        'mailfixture-77',
        'fixtureAwsSecretValue1234567890',
        'Rml4dHVyZUFwcEtleUZvclRlc3RzT25seTEyMzQ1Njc4OTA=',
    ];

    private string $originalCwd;

    /** @var array<string, string> */
    private array $fingerprints = [];

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        chdir($this->tempDir());
        foreach (['laravel-8', 'laravel-10', 'laravel-11', 'laravel-12'] as $fixture) {
            $this->fingerprints[$fixture] = Fixtures::fingerprint(Fixtures::project($fixture));
        }
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        foreach ($this->fingerprints as $fixture => $fingerprint) {
            self::assertSame($fingerprint, Fixtures::fingerprint(Fixtures::project($fixture)), "Fixture {$fixture} was modified.");
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input, ?FakeProcessRunner &$runner = null, int $verbosity = 0): CommandTester
    {
        $services = Services::fromBasePath(dirname(__DIR__, 2));
        $services->useProcessRunnerFactory(static function (AuditLog $audit, InspectionOptions $options) use (&$runner): FakeProcessRunner {
            $runner = (new FakeProcessRunner([], $audit))
                ->respond('composer --version', ProcessResult::completed(0, 'Composer version 2.10.3'))
                ->respond('composer audit', ProcessResult::completed(1, Fixtures::json('composer/audit-with-advisories.json')))
                ->respond('composer outdated', ProcessResult::completed(0, Fixtures::json('composer/outdated-locked.json')));

            return $runner;
        });

        $tester = new CommandTester((new Application($services))->find($command));
        $tester->execute($input, ['verbosity' => $verbosity ?: OutputInterface::VERBOSITY_NORMAL, 'capture_stderr_separately' => true]);

        return $tester;
    }

    private function reportsDir(): string
    {
        return $this->tempDir() . DIRECTORY_SEPARATOR . 'reports';
    }

    private static function assertNoSecrets(string $text): void
    {
        foreach (self::FIXTURE_SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testInspectPrintsAReportAndSavesResults(): void
    {
        $tester = $this->runCommand('inspect', ['path' => Fixtures::project('laravel-8'), '--offline' => true], verbosity: OutputInterface::VERBOSITY_VERBOSE);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Laravel 8.83.27', $display);
        self::assertStringContainsString('Laravel 8 no longer receives security fixes', $display);
        self::assertStringContainsString('Offline mode', $display);
        self::assertNoSecrets($display . $tester->getErrorOutput());

        $saved = glob($this->reportsDir() . '/*.json') ?: [];
        self::assertCount(1, $saved);
        self::assertNoSecrets((string) file_get_contents($saved[0]));

        $audit = (string) file_get_contents($this->reportsDir() . '/audit.log');
        self::assertStringContainsString('composer --version', $audit);
        self::assertStringNotContainsString('composer audit', $audit, 'Offline mode never calls Packagist.');
    }

    public function testAnalyzeIsAnAliasOfInspect(): void
    {
        $services = Services::fromBasePath(dirname(__DIR__, 2));

        self::assertSame('inspect', (new Application($services))->find('analyze')->getName());
    }

    public function testOnlineDependencyCheckUsesComposerSafely(): void
    {
        $runner = null;
        $tester = $this->runCommand('dependencies', ['path' => Fixtures::project('laravel-8'), '--no-save' => true], $runner);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('known security advisories', $tester->getDisplay());
        self::assertNotNull($runner);
        foreach ($runner->commands as $command) {
            self::assertContains('--no-plugins', $command->arguments);
        }
        self::assertFileDoesNotExist($this->reportsDir(), '--no-save writes nothing.');
    }

    public function testFileFormatsAreWritten(): void
    {
        $path = Fixtures::project('laravel-11');

        $markdown = $this->runCommand('inspect', ['path' => $path, '--offline' => true, '--format' => 'markdown', '--output' => 'out/report.md']);
        self::assertSame(0, $markdown->getStatusCode());
        self::assertStringContainsString('## Dependencies', (string) file_get_contents($this->tempDir() . '/out/report.md'));

        $html = $this->runCommand('inspect', ['path' => $path, '--offline' => true, '--format' => 'html']);
        self::assertSame(0, $html->getStatusCode());
        self::assertCount(1, glob($this->reportsDir() . '/*.html') ?: []);
    }

    public function testJsonToStandardOutput(): void
    {
        $tester = $this->runCommand('inspect', ['path' => Fixtures::project('laravel-8'), '--offline' => true, '--format' => 'json', '--output' => '-', '--no-save' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('8.83.27', $data['project']['laravel_version']);
        self::assertNoSecrets($tester->getDisplay());
    }

    public function testSingleAnalyzerCommandsAndSelectionOptions(): void
    {
        $environment = $this->runCommand('environment', ['path' => Fixtures::project('laravel-10'), '--offline' => true, '--no-save' => true]);
        self::assertStringContainsString('Infrastructure drivers', $environment->getDisplay());
        self::assertStringNotContainsString('Direct dependencies', $environment->getDisplay());

        $only = $this->runCommand('inspect', ['path' => Fixtures::project('laravel-10'), '--offline' => true, '--no-save' => true, '--exclude' => ['environment']]);
        self::assertStringNotContainsString('Infrastructure drivers', $only->getDisplay());
        self::assertStringContainsString('Direct dependencies', $only->getDisplay());
    }

    public function testFailOnThresholdControlsTheExitCode(): void
    {
        $path = Fixtures::project('laravel-8');

        self::assertSame(2, $this->runCommand('inspect', ['path' => $path, '--offline' => true, '--no-save' => true, '--fail-on' => 'medium'])->getStatusCode());
        self::assertSame(0, $this->runCommand('inspect', ['path' => $path, '--offline' => true, '--no-save' => true, '--fail-on' => 'high'])->getStatusCode());
    }

    public function testValidationErrors(): void
    {
        $cases = [
            [['path' => $this->tempDir() . '/missing'], 'does not exist'],
            [['path' => Fixtures::project('not-laravel')], 'is not a Laravel application'],
            [['path' => Fixtures::project('lumen')], 'Lumen'],
            [['path' => Fixtures::project('laravel-8'), '--only' => ['secrity']], 'Unknown analyzer: secrity'],
            [['path' => Fixtures::project('laravel-8'), '--format' => 'pdf'], 'Unknown format "pdf"'],
            [['path' => Fixtures::project('laravel-8'), '--fail-on' => 'urgent'], 'Unknown severity'],
            [['path' => Fixtures::project('laravel-8'), '--output' => 'x.txt'], '--output needs a file format'],
            [['path' => Fixtures::project('laravel-8'), '--format' => 'html', '--no-save' => true], '--no-save with a file format needs --output'],
        ];

        foreach ($cases as [$input, $message]) {
            $tester = $this->runCommand('inspect', $input + ['--offline' => true]);
            self::assertSame(1, $tester->getStatusCode(), $message);
            self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        }
    }

    public function testRefusesToWriteInsideTheInspectedProject(): void
    {
        $project = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"php": "^8.2", "laravel/framework": "^12.0"}}',
            'bootstrap/app.php' => '<?php',
        ]);
        chdir($project);
        $before = Fixtures::fingerprint($project);

        $refused = $this->runCommand('inspect', ['path' => '.', '--offline' => true]);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('inside the inspected project', $refused->getDisplay());

        $explicit = $this->runCommand('inspect', ['path' => '.', '--offline' => true, '--format' => 'markdown', '--output' => 'report.md', '--no-save' => true]);
        self::assertSame(1, $explicit->getStatusCode());

        $outside = $this->tempDir() . DIRECTORY_SEPARATOR . 'outside.md';
        $allowed = $this->runCommand('inspect', ['path' => '.', '--offline' => true, '--format' => 'markdown', '--output' => $outside, '--no-save' => true]);
        self::assertSame(0, $allowed->getStatusCode());
        self::assertFileExists($outside);

        self::assertSame($before, Fixtures::fingerprint($project), 'Nothing was written into the project.');
    }

    public function testReportRendersTheLatestSavedResults(): void
    {
        $missing = $this->runCommand('report', []);
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('No saved results found', $missing->getDisplay());

        $this->runCommand('inspect', ['path' => Fixtures::project('laravel-12'), '--offline' => true]);

        $report = $this->runCommand('report', ['--format' => 'markdown']);
        self::assertSame(0, $report->getStatusCode());
        $markdown = glob($this->reportsDir() . '/*.md') ?: [];
        self::assertCount(1, $markdown);
        self::assertStringContainsString('Composer allows plain HTTP downloads', (string) file_get_contents($markdown[0]));

        $failOn = $this->runCommand('report', ['--format' => 'console', '--fail-on' => 'high']);
        self::assertSame(2, $failOn->getStatusCode());

        $both = $this->runCommand('report', ['path' => Fixtures::project('laravel-12'), '--from' => 'x.json']);
        self::assertSame(1, $both->getStatusCode());
    }

    public function testReportWithAPathRunsAFreshInspection(): void
    {
        $tester = $this->runCommand('report', ['path' => Fixtures::project('laravel-10'), '--offline' => true, '--format' => 'html', '--output' => 'fresh.html']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('10.48.4', (string) file_get_contents($this->tempDir() . '/fresh.html'));
    }

    public function testReportFromAnExplicitFile(): void
    {
        $bad = $this->tempDir() . '/broken.json';
        file_put_contents($bad, '{');

        $tester = $this->runCommand('report', ['--from' => $bad]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not valid JSON', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    /**
     * Opt-in: runs the real Composer against Packagist. Enable with LARAINSPECT_NETWORK_TESTS=1.
     */
    public function testRealComposerAuditAgainstPackagist(): void
    {
        if (getenv('LARAINSPECT_NETWORK_TESTS') !== '1') {
            self::markTestSkipped('Network test; set LARAINSPECT_NETWORK_TESTS=1 to run it.');
        }
        $services = Services::fromBasePath(dirname(__DIR__, 2));
        $tester = new CommandTester((new Application($services))->find('dependencies'));
        $tester->execute(['path' => Fixtures::project('laravel-8'), '--no-save' => true, '--format' => 'json', '--output' => '-'], ['capture_stderr_separately' => true]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $ids = array_column($data['results'][0]['findings'], 'id');
        self::assertContains('deps.security-advisory', $ids, 'The fixture locks versions with published advisories.');
    }
}
