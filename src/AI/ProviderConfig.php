<?php

declare(strict_types=1);

namespace LaraInspect\AI;

use LaraInspect\Security\SecretPatterns;

/**
 * Resolved settings for one provider. The API key is never printed:
 * use maskedKey() for display, and __debugInfo() hides it from dumps.
 */
final readonly class ProviderConfig
{
    /**
     * @param array<string, string> $options Provider-specific settings (ANTHROPIC_VERSION, OPENROUTER_APP_NAME, ...).
     */
    public function __construct(
        public string $name,
        public string $label,
        #[\SensitiveParameter] public ?string $apiKey,
        public string $baseUrl,
        public ?string $model,
        public float $timeout = 300.0,
        public int $maxTokens = 16000,
        public int $maxRetries = 2,
        public array $options = [],
    ) {
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    public function maskedKey(): string
    {
        return $this->hasApiKey() ? SecretPatterns::mask((string) $this->apiKey) : 'not set';
    }

    public function option(string $key, ?string $default = null): ?string
    {
        $value = $this->options[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function withModel(?string $model): self
    {
        return new self($this->name, $this->label, $this->apiKey, $this->baseUrl, $model, $this->timeout, $this->maxTokens, $this->maxRetries, $this->options);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'apiKey' => $this->maskedKey(), 'baseUrl' => $this->baseUrl, 'model' => $this->model];
    }
}
