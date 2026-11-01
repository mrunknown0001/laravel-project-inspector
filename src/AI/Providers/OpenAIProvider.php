<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

/**
 * OpenAI Chat Completions (https://api.openai.com/v1).
 *
 * Uses max_completion_tokens, which replaces the deprecated max_tokens and is
 * required by reasoning models. Set OPENAI_TOKEN_PARAMETER=max_tokens when
 * OPENAI_BASE_URL points at an OpenAI-compatible server that only knows the
 * older field. OPENAI_ORGANIZATION / OPENAI_PROJECT select the billing scope.
 */
final class OpenAIProvider extends OpenAICompatibleProvider
{
    protected function maxTokensField(): string
    {
        return $this->config->option('TOKEN_PARAMETER') === 'max_tokens' ? 'max_tokens' : 'max_completion_tokens';
    }

    protected function extraHeaders(): array
    {
        return array_filter([
            'OpenAI-Organization' => $this->config->option('ORGANIZATION'),
            'OpenAI-Project' => $this->config->option('PROJECT'),
        ], static fn (?string $v): bool => $v !== null);
    }
}
