<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Integration;

use LaraInspect\AI\AIConfig;
use LaraInspect\AI\AIManager;
use LaraInspect\Console\Application;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Process\AuditLog;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Support\Config;
use LaraInspect\Support\Services;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\RecordingHttpClient;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiCommandTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const KEY = 'sk-ant-test-0123456789abcdefghij';

    private const FIXTURE_SECRETS = ['Insecure-Prod-Pass-991', 'Live-Literal-Secret-77aa', 'Redis-Pass-55', 'ntl_live_FixtureFakeKey123456', 'Old-Backup-Pass-123'];

    private string $originalCwd;

    private RecordingHttpClient $http;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        chdir($this->tempDir());
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
    }

    /**
     * @param array<string, string> $env
     */
    private function services(array $env, MockResponse ...$responses): Services
    {
        $root = dirname(__DIR__, 2);
        $services = new Services(Config::load($root . '/config/app.php'), $this->tempDir());
        /** @var array<string, array<string, mixed>> $providers */
        $providers = require $root . '/config/providers.php';
        $config = new AIConfig($providers, $env);
        $this->http = new RecordingHttpClient(...$responses);
        $http = $this->http;

        $services->useAiConfig($config);
        $services->useAiManagerFactory(static fn (?AuditLog $audit): AIManager => new AIManager($config, $http->client, $audit, static function (float $s): void {
        }));
        $services->useProcessRunnerFactory(static fn (AuditLog $audit, InspectionOptions $options): FakeProcessRunner => (new FakeProcessRunner([], $audit))
            ->respond('composer --version', ProcessResult::completed(0, 'Composer version 2.10.3')));

        return $services;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function execute(Services $services, string $command, array $input, bool $interactive = false): CommandTester
    {
        $tester = new CommandTester((new Application($services))->find($command));
        $tester->execute($input, ['interactive' => $interactive, 'capture_stderr_separately' => true]);

        return $tester;
    }

    private static function anthropic(string $text): MockResponse
    {
        return RecordingHttpClient::json(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 900, 'output_tokens' => 120]]);
    }

    private static function flat(string $text): string
    {
        // SymfonyStyle blocks prefix wrapped lines with "! ".
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/\n\s*! /', ' ', $text));
    }

    public function testAiCommandInspectsSendsRedactedResultsAndShowsLabelledOutput(): void
    {
        $services = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY], self::anthropic("## Priorities\n1. Disable `APP_DEBUG` in production."));

        $tester = self::execute($services, 'ai', ['path' => Fixtures::project('insecure-app'), '--offline' => true, '--ai-task' => ['explain-findings']]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        $display = self::flat($tester->getDisplay());
        self::assertStringContainsString('AI-assisted analysis (AI-generated)', $display);
        self::assertStringContainsString('Disable `APP_DEBUG` in production.', $display);
        self::assertStringContainsString('900 input / 120 output tokens', $display);
        self::assertStringContainsString('no source code, no .env', self::flat($tester->getErrorOutput()));

        self::assertCount(1, $this->http->requests);
        $sent = (string) json_encode($this->http->last()['body']);
        foreach (self::FIXTURE_SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $sent);
        }
        self::assertStringNotContainsString(Fixtures::project('insecure-app'), $sent);

        $saved = glob($this->tempDir() . '/reports/*.json') ?: [];
        self::assertCount(1, $saved);
        $json = json_decode((string) file_get_contents($saved[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('explain-findings', $json['ai'][0]['task']);
        self::assertTrue($json['ai'][0]['ai_generated']);

        $audit = (string) file_get_contents($this->tempDir() . '/reports/audit.log');
        self::assertStringContainsString('POST https://api.anthropic.com/v1/messages (model claude-opus-5-5)', $audit);
        self::assertStringNotContainsString(self::KEY, $audit);
    }

    public function testDryRunShowsThePromptAndSendsNothing(): void
    {
        $services = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY]);

        $tester = self::execute($services, 'ai', ['path' => Fixtures::project('insecure-app'), '--offline' => true, '--no-save' => true, '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->http->requests);
        $display = $tester->getDisplay();
        self::assertStringContainsString('AI dry run: nothing will be sent', $display);
        self::assertStringContainsString('[system]', $display);
        self::assertStringContainsString('sec.debug-in-production', $display);
        foreach (self::FIXTURE_SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $display);
        }
    }

    public function testInspectWithAiAddsTheSectionToFileReports(): void
    {
        $completion = RecordingHttpClient::json(['choices' => [['message' => ['content' => 'Upgrade Laravel first.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]]);
        $services = $this->services(['OPENAI_API_KEY' => 'sk-openai-test-key-1234567890'], $completion);

        $tester = self::execute($services, 'inspect', ['path' => Fixtures::project('laravel-10'), '--offline' => true, '--no-save' => true, '--ai' => true, '--provider' => 'openai', '--model' => 'gpt-test', '--ai-task' => ['dependencies'], '--format' => 'markdown', '--output' => 'out.md']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $markdown = (string) file_get_contents($this->tempDir() . '/out.md');
        self::assertStringContainsString('## AI-assisted analysis', $markdown);
        self::assertStringContainsString('Upgrade Laravel first.', $markdown);
        self::assertSame('gpt-test', $this->http->last()['body']['model'] ?? null);
        self::assertStringStartsWith('https://api.openai.com/v1/chat/completions', $this->http->last()['url']);
    }

    public function testAiIsOffUnlessRequestedAndNoAiAlwaysWins(): void
    {
        $services = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY, 'AI_ENABLED' => 'false']);
        self::execute($services, 'inspect', ['path' => Fixtures::project('laravel-11'), '--offline' => true, '--no-save' => true]);
        self::assertSame([], $this->http->requests);

        $enabled = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY, 'AI_ENABLED' => 'true']);
        $tester = self::execute($enabled, 'inspect', ['path' => Fixtures::project('laravel-11'), '--offline' => true, '--no-save' => true, '--no-ai' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function testUnconfiguredOrFailingProvidersAreReported(): void
    {
        $missing = self::execute($this->services([]), 'ai', ['path' => Fixtures::project('laravel-11'), '--offline' => true, '--no-save' => true]);
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('No AI provider selected', self::flat($missing->getDisplay()));

        $noKey = self::execute($this->services(['AI_PROVIDER' => 'openrouter']), 'ai', ['path' => Fixtures::project('laravel-11'), '--offline' => true, '--no-save' => true]);
        self::assertSame(1, $noKey->getStatusCode());
        self::assertStringContainsString('OPENROUTER_API_KEY is not set', self::flat($noKey->getDisplay()));

        $error = RecordingHttpClient::json(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'boom']], 500);
        $failing = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY, 'AI_MAX_RETRIES' => '0'], $error);
        $tester = self::execute($failing, 'ai', ['path' => Fixtures::project('laravel-11'), '--offline' => true, '--no-save' => true, '--ai-task' => ['architecture']]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No AI task succeeded', self::flat($tester->getDisplay()));
        self::assertStringContainsString('HTTP 500', self::flat($tester->getDisplay()));
    }

    public function testAiFromSavedResultsAndReportWithAi(): void
    {
        $services = $this->services(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY], self::anthropic('First insight'), self::anthropic('Second insight'));
        self::execute($services, 'inspect', ['path' => Fixtures::project('laravel-12'), '--offline' => true]);

        $fromSaved = self::execute($services, 'ai', ['--ai-task' => ['technical-debt']]);
        self::assertSame(0, $fromSaved->getStatusCode(), $fromSaved->getDisplay());
        self::assertStringContainsString('Using saved results', $fromSaved->getErrorOutput());
        self::assertStringContainsString('First insight', $fromSaved->getDisplay());

        $report = self::execute($services, 'report', ['--ai' => true, '--ai-task' => ['best-practices'], '--format' => 'html', '--output' => 'with-ai.html']);
        self::assertSame(0, $report->getStatusCode(), $report->getDisplay());
        self::assertStringContainsString('Second insight', (string) file_get_contents($this->tempDir() . '/with-ai.html'));
        self::assertCount(2, $this->http->requests);
    }

    public function testProviderAndConfigCommands(): void
    {
        $env = ['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::KEY, 'NOTOKENLIMIT_API_KEY' => 'ntl_live_abcdef123456', 'NOTOKENLIMIT_MODEL' => 'claude-sonnet-5.5'];
        $services = $this->services(
            $env,
            RecordingHttpClient::json(['data' => [['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'max_input_tokens' => 1000000], ['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5']], 'has_more' => false]),
            self::anthropic('OK'),
        );

        $providers = self::execute($services, 'ai:providers', []);
        $display = self::flat($providers->getDisplay());
        self::assertStringContainsString('* anthropic', $display);
        self::assertStringContainsString('sk-a****', $display);
        self::assertStringNotContainsString(self::KEY, $providers->getDisplay());
        self::assertStringContainsString('OPENAI_API_KEY is not set', $display);
        self::assertStringContainsString('not affiliated with the model vendors', $display);
        self::assertSame([], $this->http->requests, 'Without --check nothing is sent.');

        $models = self::execute($services, 'ai:models', ['provider' => 'anthropic', '--filter' => 'opus']);
        self::assertStringContainsString('claude-opus-5-5', $models->getDisplay());
        self::assertStringNotContainsString('claude-haiku-5-5', $models->getDisplay());

        $test = self::execute($services, 'config:test', []);
        self::assertSame(0, $test->getStatusCode());
        self::assertStringContainsString('Received a response from claude-opus-5-5', self::flat($test->getDisplay()));
        self::assertSame('Reply with the single word OK.', $this->http->last()['body']['messages'][0]['content'] ?? null, 'The test request contains no project data.');

        $show = self::execute($services, 'config:show', []);
        self::assertStringContainsString('ANTHROPIC_API_KEY', $show->getDisplay());
        self::assertStringNotContainsString(self::KEY, $show->getDisplay());
        self::assertStringContainsString('environment', $show->getDisplay());

        $failing = self::execute($this->services(['AI_PROVIDER' => 'openai']), 'config:test', []);
        self::assertSame(1, $failing->getStatusCode());
        self::assertStringContainsString('OPENAI_API_KEY is not set', self::flat($failing->getDisplay()));
    }

    public function testConfigInitCreatesThePrivateEnvFileOnce(): void
    {
        copy(dirname(__DIR__, 2) . '/.env.example', $this->tempDir() . '/.env.example');
        $services = $this->services([]);

        self::assertSame(0, self::execute($services, 'config:init', [])->getStatusCode());
        self::assertFileEquals($this->tempDir() . '/.env.example', $this->tempDir() . '/.env');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0o600, fileperms($this->tempDir() . '/.env') & 0o777);
        }

        file_put_contents($this->tempDir() . '/.env', "AI_PROVIDER=openai\n");
        $again = self::execute($services, 'config:init', []);
        self::assertStringContainsString('left unchanged', self::flat($again->getDisplay()));
        self::assertSame("AI_PROVIDER=openai\n", file_get_contents($this->tempDir() . '/.env'));
    }
}
