<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\AI;

use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\Exceptions\AuthenticationException;
use LaraInspect\AI\Exceptions\ConfigurationException;
use LaraInspect\AI\Exceptions\InvalidResponseException;
use LaraInspect\AI\Exceptions\ProviderUnavailableException;
use LaraInspect\AI\Exceptions\RateLimitException;
use LaraInspect\AI\Exceptions\RequestRejectedException;
use LaraInspect\AI\Exceptions\TimeoutException;
use LaraInspect\AI\ProviderConfig;
use LaraInspect\AI\Providers\AbstractHttpProvider;
use LaraInspect\AI\Providers\AnthropicProvider;
use LaraInspect\AI\Providers\NoTokenLimitProvider;
use LaraInspect\AI\Providers\OpenAIProvider;
use LaraInspect\AI\Providers\OpenRouterProvider;
use LaraInspect\Process\AuditLog;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Tests\Support\RecordingHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProvidersTest extends TestCase
{
    private const KEY = 'test-key-0123456789abcdef';

    /** @var list<float> */
    private array $sleeps = [];

    /**
     * @template T of AbstractHttpProvider
     * @param class-string<T>       $class
     * @param array<string, string> $options
     * @return T
     */
    private function provider(string $class, RecordingHttpClient $http, string $name, string $baseUrl, ?string $model = 'test-model', array $options = [], ?AuditLog $audit = null, ?string $key = self::KEY): AbstractHttpProvider
    {
        $config = new ProviderConfig($name, ucfirst($name), $key, $baseUrl, $model, 30.0, 1000, 2, $options);

        return new $class($config, $http->client, $audit, function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    private static function request(?float $temperature = null): ChatRequest
    {
        return new ChatRequest([ChatMessage::system('Be brief.'), ChatMessage::user('Hello')], maxTokens: 321, temperature: $temperature);
    }

    /**
     * @param array<string, int|float> $usage
     * @return array<string, mixed>
     */
    private static function openAiCompletion(string $content = 'Hi there', string $finish = 'stop', array $usage = ['prompt_tokens' => 12, 'completion_tokens' => 3, 'total_tokens' => 15]): array
    {
        return ['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'test-model-2026', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]], 'usage' => $usage];
    }

    // ---------------------------------------------------------------- OpenAI

    public function testOpenAiRequestAndResponse(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(self::openAiCompletion()));
        $provider = $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1', options: ['ORGANIZATION' => 'org-1']);

        $response = $provider->chat(self::request());
        $sent = $http->last();

        self::assertSame('POST', $sent['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $sent['url']);
        self::assertSame('Bearer ' . self::KEY, $sent['headers']['authorization']);
        self::assertSame('org-1', $sent['headers']['openai-organization']);
        self::assertSame(['model' => 'test-model', 'messages' => [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hello']], 'max_completion_tokens' => 321], $sent['body']);

        self::assertSame('Hi there', $response->content);
        self::assertSame('test-model-2026', $response->model);
        self::assertSame(ChatResponse::FINISH_STOP, $response->finishReason);
        self::assertSame(15, $response->usage->total());
        self::assertSame($response->usage, $provider->getLastUsage());
        self::assertSame('openai', $provider->getProviderName());
    }

    public function testTemperatureIsOnlySentWhenRequested(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(self::openAiCompletion()));
        $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1', options: ['TOKEN_PARAMETER' => 'max_tokens'])->chat(self::request(0.2));

        self::assertSame(0.2, $http->last()['body']['temperature'] ?? null);
        self::assertSame(321, $http->last()['body']['max_tokens'] ?? null, 'OPENAI_TOKEN_PARAMETER=max_tokens for older compatible servers');
    }

    public function testRefusalsLengthAndContentParts(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['choices' => [['message' => ['content' => null, 'refusal' => 'I cannot help with that.'], 'finish_reason' => 'stop']]]),
            RecordingHttpClient::json(self::openAiCompletion('', 'length')),
            RecordingHttpClient::json(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'part one, '], ['type' => 'text', 'text' => 'part two']]], 'finish_reason' => 'stop']]]),
        );
        $provider = $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1');

        $refusal = $provider->chat(self::request());
        self::assertSame(ChatResponse::FINISH_REFUSAL, $refusal->finishReason);
        self::assertSame('I cannot help with that.', $refusal->refusal);

        self::assertSame(ChatResponse::FINISH_LENGTH, $provider->chat(self::request())->finishReason, 'Empty output at the token limit is not an error.');
        self::assertSame('part one, part two', $provider->chat(self::request())->content);
    }

    public function testListModels(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(['object' => 'list', 'data' => [['id' => 'model-b', 'object' => 'model'], ['id' => 'model-a', 'object' => 'model'], ['object' => 'model']]]));
        $models = $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1', model: null)->listModels();

        self::assertSame('https://api.openai.com/v1/models', $http->last()['url']);
        self::assertSame(['model-a', 'model-b'], array_map(static fn ($m) => $m->id, $models));
    }

    // ------------------------------------------------------------ OpenRouter

    public function testOpenRouterHeadersCostAndErrors(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(self::openAiCompletion(usage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120, 'cost' => 0.00042])),
            RecordingHttpClient::json(['error' => ['code' => 402, 'message' => 'Insufficient credits', 'metadata' => ['provider_name' => 'Anthropic']]], 402),
            RecordingHttpClient::json(['choices' => [['error' => ['code' => 502, 'message' => 'Upstream died'], 'finish_reason' => 'error']]]),
        );
        $provider = $this->provider(OpenRouterProvider::class, $http, 'openrouter', 'https://openrouter.ai/api/v1', options: ['APP_URL' => 'https://example.test']);

        $response = $provider->chat(self::request());
        $sent = $http->last();
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $sent['url']);
        self::assertSame('Laravel Project Inspector', $sent['headers']['x-openrouter-title']);
        self::assertSame('Laravel Project Inspector', $sent['headers']['x-title']);
        self::assertSame('https://example.test', $sent['headers']['http-referer']);
        self::assertSame(321, $sent['body']['max_tokens'] ?? null);
        self::assertSame(0.00042, $response->usage->cost);

        try {
            $provider->chat(self::request());
            self::fail('Expected 402 to be rejected.');
        } catch (RequestRejectedException $e) {
            self::assertStringContainsString('HTTP 402', $e->getMessage());
            self::assertStringContainsString('[upstream: Anthropic]', $e->getMessage());
        }

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('Upstream died');
        $provider->chat(self::request());
    }

    // ----------------------------------------------------------- NoTokenLimit

    public function testNoTokenLimitUsesObservedFormat(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(self::openAiCompletion()),
            RecordingHttpClient::json(['error' => ['type' => 'authentication_error', 'code' => 'invalid_key', 'message' => 'Invalid API key']], 401),
            RecordingHttpClient::json(['object' => 'list', 'data' => [['id' => 'claude-sonnet-5.5', 'object' => 'model', 'name' => 'Claude Sonnet 5.5', 'tier' => 'high', 'description' => 'Sonnet']]]),
        );
        $provider = $this->provider(NoTokenLimitProvider::class, $http, 'notokenlimit', 'https://notokenlimit.com/api/v1', key: 'ntl_live_abcdef123456');

        $provider->chat(self::request());
        self::assertSame('https://notokenlimit.com/api/v1/chat/completions', $http->last()['url']);
        self::assertSame('Bearer ntl_live_abcdef123456', $http->last()['headers']['authorization']);

        try {
            $provider->chat(self::request());
            self::fail('Expected authentication failure.');
        } catch (AuthenticationException $e) {
            self::assertSame('Notokenlimit API error (HTTP 401): Invalid API key (authentication_error)', $e->getMessage());
        }

        $models = $provider->listModels();
        self::assertSame('Claude Sonnet 5.5', $models[0]->name);
        self::assertSame('Sonnet', $models[0]->description);

        $other = $this->provider(NoTokenLimitProvider::class, new RecordingHttpClient(), 'notokenlimit', 'https://notokenlimit.com/api/v1', key: 'sk-wrong-provider-key');
        self::assertStringContainsString('ntl_live_', implode(' ', $other->validateConfiguration()));
    }

    // -------------------------------------------------------------- Anthropic

    /**
     * @param list<array<string, string>> $content
     * @param array<string, mixed>        $extra
     * @return array<string, mixed>
     */
    private static function anthropicMessage(array $content, string $stop = 'end_turn', array $extra = []): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content, 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 50, 'cache_read_input_tokens' => 1000, 'output_tokens' => 7]] + $extra;
    }

    public function testAnthropicRequestShape(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(self::anthropicMessage([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Bonjour'],
        ])));
        $provider = $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://api.anthropic.com', 'claude-opus-5-5', ['EFFORT' => 'high']);

        $response = $provider->chat(self::request(0.3));
        $sent = $http->last();

        self::assertSame('https://api.anthropic.com/v1/messages', $sent['url']);
        self::assertSame(self::KEY, $sent['headers']['x-api-key']);
        self::assertArrayNotHasKey('authorization', $sent['headers']);
        self::assertSame('2026-10-21', $sent['headers']['anthropic-version']);
        self::assertSame('server-side-fallback-2026-07-01', $sent['headers']['anthropic-beta']);
        self::assertSame([
            'model' => 'claude-opus-5-5',
            'max_tokens' => 321,
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'system' => 'Be brief.',
            'output_config' => ['effort' => 'high'],
            'fallbacks' => 'default',
        ], $sent['body'], 'No temperature: it is rejected on API version 2026-10-21.');

        self::assertSame('Bonjour', $response->content, 'Only text blocks are returned.');
        self::assertSame(1050, $response->usage->inputTokens, 'Cached input tokens are included.');
        self::assertSame(7, $response->usage->outputTokens);
    }

    public function testAnthropicFallbacksOnlyWhereSupported(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(self::anthropicMessage([['type' => 'text', 'text' => 'ok']])),
            RecordingHttpClient::json(self::anthropicMessage([['type' => 'text', 'text' => 'ok']])),
            RecordingHttpClient::json(self::anthropicMessage([['type' => 'text', 'text' => 'ok']])),
        );

        $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://api.anthropic.com', 'claude-haiku-4-5')->chat(self::request());
        self::assertArrayNotHasKey('fallbacks', (array) $http->last()['body']);
        self::assertArrayNotHasKey('anthropic-beta', $http->last()['headers']);

        $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://llm-proxy.internal.test/v1', 'claude-opus-5-5')->chat(self::request());
        self::assertSame('https://llm-proxy.internal.test/v1/messages', $http->last()['url'], 'A base URL ending in /v1 is accepted.');
        self::assertArrayNotHasKey('fallbacks', (array) $http->last()['body'], 'Proxies may not support the beta.');

        $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://api.anthropic.com', 'claude-opus-5-5', ['REFUSAL_FALLBACK' => 'false'])->chat(self::request());
        self::assertArrayNotHasKey('fallbacks', (array) $http->last()['body']);
    }

    public function testAnthropicRefusalAndTruncation(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(self::anthropicMessage([], 'refusal', ['stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null]])),
            RecordingHttpClient::json(self::anthropicMessage([['type' => 'text', 'text' => 'partial']], 'max_tokens')),
            RecordingHttpClient::json(self::anthropicMessage([['type' => 'thinking', 'thinking' => '']])),
        );
        $provider = $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://api.anthropic.com', 'claude-opus-5-5');

        $refusal = $provider->chat(self::request());
        self::assertSame(ChatResponse::FINISH_REFUSAL, $refusal->finishReason);
        self::assertSame('category: cyber', $refusal->refusal);

        self::assertSame(ChatResponse::FINISH_LENGTH, $provider->chat(self::request())->finishReason);

        $this->expectException(InvalidResponseException::class);
        $provider->chat(self::request());
    }

    public function testAnthropicModelPagination(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['data' => [['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'max_input_tokens' => 1000000]], 'has_more' => true, 'last_id' => 'claude-opus-5-5']),
            RecordingHttpClient::json(['data' => [['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'max_input_tokens' => 1000000]], 'has_more' => false, 'last_id' => 'claude-haiku-5-5']),
        );
        $models = $this->provider(AnthropicProvider::class, $http, 'anthropic', 'https://api.anthropic.com')->listModels();

        self::assertSame(['claude-opus-5-5', 'claude-haiku-5-5'], array_map(static fn ($m) => $m->id, $models));
        self::assertSame(1000000, $models[0]->contextWindow);
        self::assertSame('https://api.anthropic.com/v1/models?limit=100&after_id=claude-opus-5-5', $http->requests[1]['url']);
    }

    // ------------------------------------------------------ Shared behaviour

    /**
     * @return iterable<string, array{class-string<AbstractHttpProvider>, string, string}>
     */
    public static function providers(): iterable
    {
        yield 'openai' => [OpenAIProvider::class, 'openai', 'https://api.openai.com/v1'];
        yield 'openrouter' => [OpenRouterProvider::class, 'openrouter', 'https://openrouter.ai/api/v1'];
        yield 'notokenlimit' => [NoTokenLimitProvider::class, 'notokenlimit', 'https://notokenlimit.com/api/v1'];
        yield 'anthropic' => [AnthropicProvider::class, 'anthropic', 'https://api.anthropic.com'];
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testRateLimitsAreRetriedHonouringRetryAfter(string $class, string $name, string $baseUrl): void
    {
        $success = $class === AnthropicProvider::class ? self::anthropicMessage([['type' => 'text', 'text' => 'ok']]) : self::openAiCompletion('ok');
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['error' => ['message' => 'slow down']], 429, ['retry-after' => '7']),
            RecordingHttpClient::json(['error' => ['message' => 'overloaded']], $class === AnthropicProvider::class ? 529 : 503),
            RecordingHttpClient::json($success),
        );

        self::assertSame('ok', $this->provider($class, $http, $name, $baseUrl)->chat(self::request())->content);
        self::assertCount(3, $http->requests);
        self::assertSame([7.0, 2.0], $this->sleeps);
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testRetriesStopAfterTheConfiguredLimit(string $class, string $name, string $baseUrl): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['error' => ['message' => 'busy']], 500),
            RecordingHttpClient::json(['error' => ['message' => 'busy']], 500),
            RecordingHttpClient::json(['error' => ['message' => 'busy']], 500),
        );

        try {
            $this->provider($class, $http, $name, $baseUrl)->chat(self::request());
            self::fail('Expected the provider to give up.');
        } catch (ProviderUnavailableException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }
        self::assertCount(3, $http->requests, '1 attempt + 2 retries');
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testTimeoutsAreTypedAndRetried(string $class, string $name, string $baseUrl): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::timeout(), RecordingHttpClient::timeout(), RecordingHttpClient::timeout());

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('did not respond within 30 seconds');
        try {
            $this->provider($class, $http, $name, $baseUrl)->chat(self::request());
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testInvalidResponsesAreRejected(string $class, string $name, string $baseUrl): void
    {
        foreach (['not json at all', '[1, 2, 3]', '{"unexpected": true}'] as $body) {
            $http = new RecordingHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse($body));
            try {
                $this->provider($class, $http, $name, $baseUrl)->chat(self::request());
                self::fail('Expected an invalid response error for: ' . $body);
            } catch (InvalidResponseException) {
                self::assertCount(1, $http->requests, 'Invalid responses are not retried.');
            }
        }
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testAuthenticationAndClientErrorsAreNotRetried(string $class, string $name, string $baseUrl): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['error' => ['type' => 'authentication_error', 'message' => 'Incorrect API key provided: ' . self::KEY]], 401),
            RecordingHttpClient::json(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'model not found']], 404),
        );
        $provider = $this->provider($class, $http, $name, $baseUrl);

        try {
            $provider->chat(self::request());
            self::fail('Expected authentication failure.');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage(), 'Keys echoed by the provider are redacted.');
        }
        try {
            $provider->chat(self::request());
            self::fail('Expected request rejection.');
        } catch (RequestRejectedException $e) {
            self::assertStringContainsString('model not found', $e->getMessage());
        }
        self::assertCount(2, $http->requests);
        self::assertSame([], $this->sleeps);
    }

    /**
     * @param class-string<AbstractHttpProvider> $class
     */
    #[DataProvider('providers')]
    public function testMissingConfigurationFailsBeforeAnyRequest(string $class, string $name, string $baseUrl): void
    {
        $http = new RecordingHttpClient();

        self::assertNotSame([], $this->provider($class, $http, $name, $baseUrl, key: null)->validateConfiguration());
        try {
            $this->provider($class, $http, $name, $baseUrl, key: null)->chat(self::request());
            self::fail('Expected a configuration error.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('_API_KEY is not set', $e->getMessage());
        }
        try {
            $this->provider($class, $http, $name, $baseUrl, model: null)->chat(self::request());
            self::fail('Expected a configuration error.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('No model configured', $e->getMessage());
        }
        self::assertSame([], $http->requests);
        self::assertStringContainsString('https://', implode(' ', $this->provider($class, $http, $name, 'http://api.example.test')->validateConfiguration()));
        self::assertSame([], $this->provider($class, $http, $name, 'http://localhost:8080/v1', key: 'ntl_live_local_test_key')->validateConfiguration());
    }

    public function testEveryRequestIsAuditedWithoutTheKey(): void
    {
        $audit = new AuditLog(new SecretRedactor());
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['error' => ['message' => 'nope']], 429),
            RecordingHttpClient::json(self::openAiCompletion()),
        );
        $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1', audit: $audit)->chat(self::request());

        $entries = $audit->entries();
        self::assertCount(2, $entries);
        self::assertSame(['http-429', 'completed'], [$entries[0]->outcome, $entries[1]->outcome]);
        self::assertSame('POST https://api.openai.com/v1/chat/completions (model test-model)', $entries[1]->command);
        self::assertTrue($entries[1]->network);
        self::assertStringNotContainsString(self::KEY, (string) json_encode(array_map(static fn ($e) => $e->toArray(), $entries)));
    }

    public function testConfigNeverDumpsTheKey(): void
    {
        $config = new ProviderConfig('openai', 'OpenAI', 'sk-secret-value-123456', 'https://api.openai.com/v1', 'm');

        self::assertStringNotContainsString('sk-secret-value-123456', print_r($config, true));
        self::assertStringNotContainsString('sk-secret-value-123456', var_export($config->__debugInfo(), true));
        self::assertSame('sk-s**** (22 characters)', $config->maskedKey());
    }

    public function testRateLimitExceptionCarriesRetryAfter(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(['error' => ['message' => 'slow']], 429, ['retry-after' => '120']),
            RecordingHttpClient::json(['error' => ['message' => 'slow']], 429, ['retry-after' => '120']),
            RecordingHttpClient::json(['error' => ['message' => 'slow']], 429, ['retry-after' => '120']),
        );
        try {
            $this->provider(OpenAIProvider::class, $http, 'openai', 'https://api.openai.com/v1')->chat(self::request());
            self::fail('Expected rate limit.');
        } catch (RateLimitException $e) {
            self::assertSame(120, $e->retryAfterSeconds);
        }
        self::assertSame([30.0, 30.0], $this->sleeps, 'Waits are capped at 30 seconds.');
    }
}
