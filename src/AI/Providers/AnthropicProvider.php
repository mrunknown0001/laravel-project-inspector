<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\DTO\ModelInfo;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\InvalidResponseException;
use LaraInspect\Support\Arr;

/**
 * Anthropic Messages API (POST /v1/messages), API version 2026-10-21.
 *
 * Differences from the OpenAI format handled here:
 *  - x-api-key + anthropic-version headers instead of Bearer auth
 *  - system prompt is a top-level field, not a message
 *  - temperature/top_p/top_k are never sent (rejected on API version 2026-10-21)
 *  - responses are content blocks; only "text" blocks are returned
 *  - stop_reason "refusal" is surfaced, and current models opt into
 *    server-side refusal fallbacks ("fallbacks": "default")
 */
final class AnthropicProvider extends AbstractHttpProvider
{
    public const API_VERSION = '2026-10-21';

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Models that accept the "default" server-side fallback mode. */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const MAX_MODEL_PAGES = 10;

    protected function headers(): array
    {
        return [
            'x-api-key' => (string) $this->config->apiKey,
            'anthropic-version' => $this->config->option('VERSION', self::API_VERSION) ?? self::API_VERSION,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $model = $this->requireModel($request->model);
        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? $this->config->maxTokens,
            'messages' => array_map(static fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content], $request->conversation()),
        ];
        $system = $request->systemPrompt();
        if ($system !== '') {
            $body['system'] = $system;
        }
        $effort = strtolower((string) $this->config->option('EFFORT'));
        if (in_array($effort, self::EFFORT_LEVELS, true)) {
            $body['output_config'] = ['effort' => $effort];
        }

        $headers = [];
        if ($this->usesFallbacks($model)) {
            $body['fallbacks'] = 'default';
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        $data = $this->send('POST', $this->path('/v1/messages'), $body, 'message', $headers);
        $response = $this->parse($data, $model);
        $this->lastUsage = $response->usage;

        return $response;
    }

    public function listModels(): array
    {
        $models = [];
        $after = null;
        for ($page = 0; $page < self::MAX_MODEL_PAGES; $page++) {
            $query = '?limit=100' . ($after !== null ? '&after_id=' . rawurlencode($after) : '');
            $data = $this->send('GET', $this->path('/v1/models') . $query, null, 'list models');
            foreach (Arr::listOfArrays($data, 'data') as $entry) {
                $id = Arr::string($entry, 'id');
                if ($id !== '') {
                    $context = $entry['max_input_tokens'] ?? null;
                    $models[] = new ModelInfo($id, Arr::string($entry, 'display_name') ?: null, is_int($context) ? $context : null);
                }
            }
            $last = Arr::string($data, 'last_id');
            if (!Arr::bool($data, 'has_more') || $last === '') {
                break;
            }
            $after = $last;
        }

        return $models;
    }

    /**
     * Refusal fallbacks re-run a declined request on another model inside the
     * same call. Enabled by default for models that support the "default" mode
     * on the first-party API; ANTHROPIC_REFUSAL_FALLBACK=false turns it off.
     */
    private function usesFallbacks(string $model): bool
    {
        $setting = strtolower((string) $this->config->option('REFUSAL_FALLBACK', 'true'));
        $host = parse_url($this->config->baseUrl, PHP_URL_HOST);

        return !in_array($setting, ['0', 'false', 'no', 'off'], true)
            && in_array($model, self::FALLBACK_MODELS, true)
            && $host === 'api.anthropic.com';
    }

    /**
     * Accepts ANTHROPIC_BASE_URL with or without a trailing /v1.
     */
    private function path(string $path): string
    {
        return str_ends_with($this->config->baseUrl, '/v1') ? substr($path, 3) : $path;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parse(array $data, string $model): ChatResponse
    {
        if (!is_array($data['content'] ?? null) || !isset($data['stop_reason'])) {
            throw new InvalidResponseException('Anthropic returned a response without message content.');
        }
        $text = '';
        foreach (Arr::listOfArrays($data, 'content') as $block) {
            if (Arr::string($block, 'type') === 'text') {
                $text .= Arr::string($block, 'text');
            }
        }

        $stop = Arr::string($data, 'stop_reason');
        $finish = match ($stop) {
            'end_turn', 'stop_sequence' => ChatResponse::FINISH_STOP,
            'max_tokens', 'model_context_window_exceeded' => ChatResponse::FINISH_LENGTH,
            'refusal' => ChatResponse::FINISH_REFUSAL,
            default => ChatResponse::FINISH_OTHER,
        };

        $refusal = null;
        if ($finish === ChatResponse::FINISH_REFUSAL) {
            $details = Arr::array($data, 'stop_details');
            $category = Arr::string($details, 'category');
            $refusal = Arr::string($details, 'explanation') ?: ($category !== '' ? 'category: ' . $category : 'the model declined the request');
        }
        if ($text === '' && $finish === ChatResponse::FINISH_STOP) {
            throw new InvalidResponseException('Anthropic returned a message without text content.');
        }

        /** @var array<string, mixed> $usage */
        $usage = Arr::array($data, 'usage');
        $int = static fn (string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;
        $hasUsage = isset($usage['input_tokens']) || isset($usage['output_tokens']);
        // input_tokens excludes cached tokens; report the full prompt size.
        $input = $int('input_tokens') + $int('cache_creation_input_tokens') + $int('cache_read_input_tokens');
        $id = Arr::string($data, 'id');

        return new ChatResponse(
            $text,
            $this->config->name,
            Arr::string($data, 'model', $model) ?: $model,
            $finish,
            $hasUsage ? new Usage($input, $int('output_tokens'), $input + $int('output_tokens')) : Usage::none(),
            $id === '' ? null : $id,
            $refusal,
        );
    }
}
