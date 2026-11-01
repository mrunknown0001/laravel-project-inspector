<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\AI;

use LaraInspect\AI\AIAnalyzer;
use LaraInspect\AI\Contracts\AIProviderInterface;
use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\RateLimitException;
use LaraInspect\AI\PromptBuilder;
use LaraInspect\AI\Tasks\TaskCatalog;
use LaraInspect\Core\Inspector;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Process\AuditLog;
use LaraInspect\Reports\HtmlReporter;
use LaraInspect\Reports\MarkdownReporter;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Support\Services;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PromptAndAnalyzerTest extends TestCase
{
    private const FIXTURE_SECRETS = ['Insecure-Prod-Pass-991', 'Live-Literal-Secret-77aa', 'Redis-Pass-55', 'ntl_live_FixtureFakeKey123456', 'mg-Default-Secret-123', 'Old-Backup-Pass-123', 'Frontend-Secret-123abc'];

    private static ?InspectionReport $report = null;

    /**
     * A full inspection of the insecure fixture, run once.
     */
    private static function report(): InspectionReport
    {
        if (self::$report === null) {
            $redactor = new SecretRedactor();
            $services = Services::fromBasePath(dirname(__DIR__, 3));
            $context = Fixtures::context(Fixtures::project('insecure-app'), new FakeProcessRunner(), offline: true, redactor: $redactor);
            self::$report = (new Inspector('0.1.0', new AuditLog($redactor)))->inspect($context, $services->analyzers()->select());
        }

        return self::$report;
    }

    /**
     * Provider double that records requests and replays scripted outcomes.
     */
    private static function provider(ChatResponse|\Throwable ...$outcomes): AIProviderInterface
    {
        return new class (array_values($outcomes)) implements AIProviderInterface {
            /** @var list<ChatRequest> */
            public array $requests = [];

            /** @param list<ChatResponse|\Throwable> $outcomes */
            public function __construct(private array $outcomes)
            {
            }

            public function getProviderName(): string
            {
                return 'fake';
            }

            public function validateConfiguration(): array
            {
                return [];
            }

            public function listModels(): array
            {
                return [];
            }

            public function chat(ChatRequest $request): ChatResponse
            {
                $this->requests[] = $request;
                $outcome = array_shift($this->outcomes) ?? throw new \LogicException('No scripted outcome left.');
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }

            public function getLastUsage(): ?Usage
            {
                return null;
            }
        };
    }

    private static function userMessage(ChatRequest $request): string
    {
        foreach ($request->messages as $message) {
            if ($message->role === ChatMessage::USER) {
                return $message->content;
            }
        }
        self::fail('No user message.');
    }

    public function testPromptsContainResultsButNoSecretsPathsOrAuditLog(): void
    {
        $builder = new PromptBuilder(new SecretRedactor(), 200_000);

        foreach (TaskCatalog::select(['all']) as $task) {
            [$request] = $builder->build(self::report(), $task);
            $prompt = implode("\n", array_map(static fn (ChatMessage $m): string => $m->content, $request->messages));

            foreach (self::FIXTURE_SECRETS as $secret) {
                self::assertStringNotContainsString($secret, $prompt, $task->key);
            }
            self::assertStringNotContainsString(Fixtures::project('insecure-app'), $prompt, 'The absolute path is replaced.');
            self::assertStringNotContainsString('composer --version', $prompt, 'The audit log is not sent.');
            self::assertStringNotContainsString('<?php', $prompt, 'No source code is sent.');
            self::assertSame(PromptBuilder::SYSTEM_PROMPT, $request->messages[0]->content);
            self::assertStringContainsString($task->instruction, $prompt);
        }
    }

    public function testLocalPathsAreRemovedInEveryFormBeforeTruncation(): void
    {
        foreach (['C:\\Users\\adam\\sites\\shop', '/home/adam/we"ird/shop'] as $root) {
            $data = \LaraInspect\Tests\Support\SampleReport::create($root)->toArray();
            $data['results'][0]['notes'] = [str_repeat('x', 280) . ' see ' . $root . '/storage/logs/laravel.log for details'];
            $data['results'][1]['findings'][1]['evidence'] = 'In ' . $root . '\\app\\Http\\Kernel.php and ' . strtoupper($root) . '/routes/web.php';
            /** @var array<string, mixed> $data */
            $report = InspectionReport::fromArray($data);

            [$request] = (new PromptBuilder(new SecretRedactor(), 200_000, '/home/adam'))->build($report, TaskCatalog::all()['explain-findings']);
            $prompt = self::userMessage($request);

            self::assertStringNotContainsString('adam', $prompt, 'No part of a local path may reach the provider: ' . $root);
            self::assertStringContainsString('<project>', $prompt);
        }

        [$home] = (new PromptBuilder(new SecretRedactor(), 200_000, '/home/adam'))->build(
            InspectionReport::fromArray(['results' => [['analyzer' => 'environment', 'title' => 'Environment', 'notes' => ['Cache in /home/adam/.cache/composer']]]] + \LaraInspect\Tests\Support\SampleReport::create('/srv/app')->toArray()),
            TaskCatalog::all()['explain-findings'],
        );
        self::assertStringContainsString('~/.cache/composer', self::userMessage($home));
    }

    public function testTasksOnlyIncludeTheirAnalyzers(): void
    {
        $builder = new PromptBuilder(new SecretRedactor(), 200_000);
        [$request] = $builder->build(self::report(), TaskCatalog::all()['dependencies']);
        $json = self::userMessage($request);

        self::assertStringContainsString('"analyzer": "dependencies"', $json);
        self::assertStringNotContainsString('"analyzer": "security"', $json);
        self::assertStringContainsString('"severity_counts"', $json);

        [$all] = $builder->build(self::report(), TaskCatalog::all()['explain-findings']);
        $context = self::userMessage($all);
        self::assertStringContainsString('sec.debug-in-production', $context);
        $decoded = json_decode((string) preg_replace('/^.*```json\n(.*)\n```$/s', '$1', $context), true);
        self::assertIsArray($decoded);
        $weights = ['CRITICAL' => 5, 'HIGH' => 4, 'MEDIUM' => 3, 'LOW' => 2, 'INFO' => 1];
        foreach ($decoded['analyzers'] as $analyzer) {
            $order = array_map(static fn (array $f): int => $weights[$f['severity']], $analyzer['findings']);
            $sorted = $order;
            rsort($sorted);
            self::assertSame($sorted, $order, 'Findings are ordered by severity within ' . $analyzer['analyzer']);
        }
        self::assertStringNotContainsString('"routes": [', $context, 'The full route list is not sent; only counts.');
    }

    public function testLargeContextsAreCondensedAndSaySo(): void
    {
        [$full, $fullNotes] = (new PromptBuilder(new SecretRedactor(), 500_000))->build(self::report(), TaskCatalog::all()['explain-findings']);
        [$small, $notes] = (new PromptBuilder(new SecretRedactor(), 20_000))->build(self::report(), TaskCatalog::all()['explain-findings']);

        self::assertSame([], $fullNotes);
        self::assertLessThan($full->characterCount(), $small->characterCount());
        self::assertStringContainsString('AI_MAX_INPUT_CHARS', implode(' ', $notes));
        self::assertNotNull(json_decode((string) preg_replace('/^.*```json\n(.*)\n```$/s', '$1', self::userMessage($small)), true), 'Condensed context is still valid JSON.');
    }

    public function testAnalyzerRecordsSuccessFailureRefusalAndTruncation(): void
    {
        $usage = new Usage(1000, 200, 1200);
        $provider = self::provider(
            new ChatResponse("## Summary\nRotate the leaked key DB_PASSWORD=Insecure-Prod-Pass-991 now.", 'fake', 'fake-model', ChatResponse::FINISH_STOP, $usage),
            new RateLimitException('Fake API error (HTTP 429): slow down'),
            new ChatResponse('', 'fake', 'fake-model', ChatResponse::FINISH_REFUSAL, Usage::none(), refusal: 'category: cyber'),
            new ChatResponse('Partial answer', 'fake', 'fake-model', ChatResponse::FINISH_LENGTH, $usage),
        );
        $redactor = new SecretRedactor();
        $analyzer = new AIAnalyzer(new PromptBuilder($redactor), $redactor);
        $started = [];

        $insights = $analyzer->run(self::report(), $provider, TaskCatalog::select(['explain-findings', 'security-fixes', 'architecture', 'documentation']), static function ($task) use (&$started): void {
            $started[] = $task->key;
        });

        self::assertSame(['explain-findings', 'security-fixes', 'architecture', 'documentation'], $started);
        self::assertTrue($insights[0]->succeeded());
        self::assertStringNotContainsString('Insecure-Prod-Pass-991', $insights[0]->content, 'Model output is redacted too.');
        self::assertSame(1200, $insights[0]->usage->total());

        self::assertFalse($insights[1]->succeeded());
        self::assertSame('error', $insights[1]->finishReason);
        self::assertStringContainsString('HTTP 429', (string) $insights[1]->error);

        self::assertSame('The model declined the request.', $insights[2]->error);
        self::assertStringContainsString('category: cyber', implode(' ', $insights[2]->notes));

        self::assertTrue($insights[3]->succeeded());
        self::assertStringContainsString('cut off', implode(' ', $insights[3]->notes));
    }

    public function testDryRunPreviewSendsNothing(): void
    {
        $provider = self::provider();
        $previews = (new AIAnalyzer(new PromptBuilder(new SecretRedactor()), new SecretRedactor()))->preview(self::report(), TaskCatalog::select([]));

        self::assertCount(3, $previews);
        self::assertSame([], $provider->requests ?? []);
    }

    public function testReportsLabelAndEscapeAiContent(): void
    {
        $insight = new \LaraInspect\Model\AIInsight('explain-findings', 'Findings explained', 'anthropic', 'claude-opus-5-5', "## Advice\n<script>alert(1)</script> Use `auth` middleware.", 'stop', new Usage(10, 5, 15), '2026-11-01T12:00:00+00:00');
        $report = self::report()->withInsights([$insight], []);

        $markdown = (new MarkdownReporter())->render($report);
        self::assertStringContainsString('## AI-assisted analysis', $markdown);
        self::assertStringContainsString('**AI-generated content.**', $markdown);
        self::assertStringNotContainsString('<script>', $markdown);
        self::assertStringContainsString('_anthropic · claude-opus-5-5 · 10 input / 5 output tokens_', $markdown);

        $html = (new HtmlReporter())->render($report);
        self::assertStringContainsString('AI-generated</span>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);

        $restored = InspectionReport::fromArray($report->toArray());
        self::assertEquals($insight, $restored->insights[0]);
        self::assertTrue($report->toArray()['ai'][0]['ai_generated']);
        self::assertSame(count(self::report()->findings()), count($restored->findings()), 'AI never adds findings.');
    }
}
