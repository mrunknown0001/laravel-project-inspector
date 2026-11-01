<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

use LaraInspect\AI\DTO\Usage;

/**
 * OpenRouter (https://openrouter.ai/api/v1), an OpenAI-compatible router.
 *
 * Sends the optional attribution headers (X-OpenRouter-Title, and HTTP-Referer
 * when OPENROUTER_APP_URL is set), reports usage.cost, and includes the
 * upstream provider name from error metadata.
 */
final class OpenRouterProvider extends OpenAICompatibleProvider
{
    public const DEFAULT_APP_NAME = 'Laravel Project Inspector';

    protected function extraHeaders(): array
    {
        $title = $this->config->option('APP_NAME', self::DEFAULT_APP_NAME) ?? self::DEFAULT_APP_NAME;

        return array_filter([
            'X-OpenRouter-Title' => $title,
            'X-Title' => $title,
            'HTTP-Referer' => $this->config->option('APP_URL'),
        ], static fn (?string $v): bool => $v !== null);
    }

    protected function parseUsage(array $usage): Usage
    {
        $base = parent::parseUsage($usage);
        $cost = $usage['cost'] ?? null;

        return new Usage($base->inputTokens, $base->outputTokens, $base->totalTokens, is_int($cost) || is_float($cost) ? (float) $cost : null);
    }

    protected function errorMessage(?array $body): ?string
    {
        $message = parent::errorMessage($body);
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $metadata = is_array($error['metadata'] ?? null) ? $error['metadata'] : [];
        $provider = $metadata['provider_name'] ?? null;

        return $message !== null && is_string($provider) ? sprintf('%s [upstream: %s]', $message, $provider) : $message;
    }
}
