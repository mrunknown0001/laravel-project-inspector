<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\DTO\ModelInfo;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\InvalidResponseException;
use LaraInspect\AI\Exceptions\ProviderUnavailableException;
use LaraInspect\Support\Arr;

/**
 * Shared implementation of the OpenAI Chat Completions wire format
 * (POST {base}/chat/completions, GET {base}/models, Bearer auth).
 *
 * Subclasses override only what differs: token-limit field name, extra
 * headers, usage fields and error details.
 */
abstract class OpenAICompatibleProvider extends AbstractHttpProvider
{
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ] + $this->extraHeaders();
    }

    /**
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $model = $this->requireModel($request->model);
        $body = [
            'model' => $model,
            'messages' => array_map(static fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content], $request->messages),
            $this->maxTokensField() => $request->maxTokens ?? $this->config->maxTokens,
        ];
        // Only sent when asked for: several current models reject non-default sampling settings.
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        $data = $this->send('POST', '/chat/completions', $body, 'chat completion');
        $response = $this->parseChat($data, $model);
        $this->lastUsage = $response->usage;

        return $response;
    }

    public function listModels(): array
    {
        $data = $this->send('GET', '/models', null, 'list models');
        $models = [];
        foreach (Arr::listOfArrays($data, 'data') as $entry) {
            $id = Arr::string($entry, 'id');
            if ($id === '') {
                continue;
            }
            $name = Arr::string($entry, 'name');
            $description = Arr::string($entry, 'description');
            $context = $entry['context_length'] ?? null;
            $models[] = new ModelInfo($id, $name === '' ? null : $name, is_int($context) ? $context : null, $description === '' ? null : $description);
        }
        usort($models, static fn (ModelInfo $a, ModelInfo $b): int => $a->id <=> $b->id);

        return $models;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function parseChat(array $data, string $model): ChatResponse
    {
        $choices = Arr::listOfArrays($data, 'choices');
        $choice = $choices[0] ?? null;
        if ($choice === null) {
            $error = $this->errorMessage($data);
            throw $error !== null
                ? new ProviderUnavailableException(sprintf('%s returned an error: %s', $this->config->label, $error))
                : new InvalidResponseException(sprintf('%s returned no choices.', $this->config->label));
        }
        // Errors that occur after generation started arrive with HTTP 200 inside the choice.
        if (is_array($choice['error'] ?? null)) {
            /** @var array<string, mixed> $choiceError */
            $choiceError = ['error' => $choice['error']];
            throw new ProviderUnavailableException(sprintf('%s failed during generation: %s', $this->config->label, $this->errorMessage($choiceError) ?? 'unknown error'));
        }

        $message = Arr::array($choice, 'message');
        $content = self::text($message['content'] ?? null);
        $refusal = is_string($message['refusal'] ?? null) && $message['refusal'] !== '' ? $message['refusal'] : null;
        $finish = match (Arr::string($choice, 'finish_reason')) {
            'stop', 'end_turn' => ChatResponse::FINISH_STOP,
            'length', 'max_tokens' => ChatResponse::FINISH_LENGTH,
            'content_filter' => ChatResponse::FINISH_FILTERED,
            default => ChatResponse::FINISH_OTHER,
        };
        if ($refusal !== null) {
            $finish = ChatResponse::FINISH_REFUSAL;
        }
        if ($content === '' && $finish === ChatResponse::FINISH_STOP) {
            throw new InvalidResponseException(sprintf('%s returned an empty message.', $this->config->label));
        }

        /** @var array<string, mixed> $usage */
        $usage = Arr::array($data, 'usage');
        $id = Arr::string($data, 'id');

        return new ChatResponse(
            $content,
            $this->config->name,
            Arr::string($data, 'model', $model) ?: $model,
            $finish,
            $this->parseUsage($usage),
            $id === '' ? null : $id,
            $refusal,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function parseUsage(array $usage): Usage
    {
        $int = static fn (string $key): ?int => is_int($usage[$key] ?? null) ? $usage[$key] : null;

        return new Usage($int('prompt_tokens'), $int('completion_tokens'), $int('total_tokens'));
    }

    /**
     * Message content is a string, or a list of parts like [{"type": "text", "text": "..."}].
     */
    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return implode('', $parts);
    }
}
