<?php

declare(strict_types=1);

namespace LaraInspect\AI;

use LaraInspect\AI\Exceptions\ConfigurationException;
use LaraInspect\Parsing\EnvFileParser;

/**
 * AI settings from (highest priority first) process environment variables,
 * the inspector's own .env file, and config/providers.php defaults.
 *
 * This is the inspector's configuration, never the inspected project's .env.
 */
final class AIConfig
{
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_FILE = '.env';
    public const SOURCE_DEFAULT = 'default';

    private const GLOBAL_DEFAULTS = [
        'AI_PROVIDER' => null,
        'AI_MODEL' => null,
        'AI_ENABLED' => 'false',
        'AI_TIMEOUT' => '300',
        'AI_MAX_TOKENS' => '16000',
        'AI_MAX_RETRIES' => '2',
        'AI_MAX_INPUT_CHARS' => '60000',
    ];

    /** Provider-specific option keys (without prefix) passed to adapters. */
    private const PROVIDER_OPTIONS = [
        'anthropic' => ['VERSION', 'EFFORT', 'REFUSAL_FALLBACK'],
        'openai' => ['ORGANIZATION', 'PROJECT', 'TOKEN_PARAMETER'],
        'openrouter' => ['APP_NAME', 'APP_URL'],
        'notokenlimit' => [],
    ];

    /**
     * @param array<string, array<string, mixed>> $providers config/providers.php
     * @param array<string, string>               $environment Process environment.
     * @param array<string, string>               $file        Parsed inspector .env.
     */
    public function __construct(
        private readonly array $providers,
        private readonly array $environment,
        private readonly array $file = [],
    ) {
    }

    /**
     * @param array<string, string>|null $environment Defaults to getenv().
     */
    public static function load(string $basePath, ?array $environment = null): self
    {
        $providers = require $basePath . '/config/providers.php';
        if (!is_array($providers)) {
            throw new \RuntimeException('config/providers.php must return an array.');
        }
        $envFile = $basePath . '/.env';
        $file = is_file($envFile) && is_readable($envFile) ? (new EnvFileParser())->parse((string) file_get_contents($envFile)) : [];

        /** @var array<string, array<string, mixed>> $providers */
        return new self($providers, $environment ?? self::processEnvironment(), $file);
    }

    /**
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $provider): array
    {
        return $this->providers[$provider] ?? throw new ConfigurationException(sprintf(
            'Unknown AI provider "%s". Available: %s.',
            $provider,
            implode(', ', $this->providerNames()),
        ));
    }

    /**
     * @return array{0: string|null, 1: string} value and where it came from
     */
    public function value(string $key): array
    {
        if (isset($this->environment[$key]) && $this->environment[$key] !== '') {
            return [$this->environment[$key], self::SOURCE_ENVIRONMENT];
        }
        if (isset($this->file[$key]) && $this->file[$key] !== '') {
            return [$this->file[$key], self::SOURCE_FILE];
        }

        return [self::GLOBAL_DEFAULTS[$key] ?? null, self::SOURCE_DEFAULT];
    }

    public function get(string $key): ?string
    {
        return $this->value($key)[0];
    }

    public function defaultProvider(): ?string
    {
        $provider = $this->get('AI_PROVIDER');

        return $provider === null ? null : strtolower($provider);
    }

    public function isEnabled(): bool
    {
        return in_array(strtolower((string) $this->get('AI_ENABLED')), ['1', 'true', 'yes', 'on'], true);
    }

    public function maxInputChars(): int
    {
        return max(4000, (int) $this->get('AI_MAX_INPUT_CHARS'));
    }

    /**
     * Resolves a provider's settings. Model precedence: $modelOverride (--model),
     * <PREFIX>_MODEL, AI_MODEL (only for the AI_PROVIDER provider), the provider default.
     */
    public function provider(string $name, ?string $modelOverride = null): ProviderConfig
    {
        $name = strtolower($name);
        $definition = $this->definition($name);
        $prefix = (string) ($definition['env_prefix'] ?? strtoupper($name));

        $model = $modelOverride
            ?? $this->get($prefix . '_MODEL')
            ?? ($this->defaultProvider() === $name ? $this->get('AI_MODEL') : null)
            ?? (is_string($definition['default_model'] ?? null) ? $definition['default_model'] : null);

        $options = [];
        foreach (self::PROVIDER_OPTIONS[$name] ?? [] as $option) {
            $value = $this->get($prefix . '_' . $option);
            if ($value !== null) {
                $options[$option] = $value;
            }
        }

        return new ProviderConfig(
            $name,
            (string) ($definition['label'] ?? $name),
            $this->get($prefix . '_API_KEY'),
            rtrim($this->get($prefix . '_BASE_URL') ?? (string) ($definition['base_url'] ?? ''), '/'),
            $model,
            (float) max(5, (int) $this->get('AI_TIMEOUT')),
            max(256, (int) $this->get('AI_MAX_TOKENS')),
            max(0, min(5, (int) $this->get('AI_MAX_RETRIES'))),
            $options,
        );
    }

    /**
     * Every setting with its source, for "config:show". Keys are masked by the caller.
     *
     * @return list<array{0: string, 1: string|null, 2: string}>
     */
    public function describe(): array
    {
        $rows = [];
        foreach (array_keys(self::GLOBAL_DEFAULTS) as $key) {
            [$value, $source] = $this->value($key);
            $rows[] = [$key, $value, $source];
        }
        foreach ($this->providers as $name => $definition) {
            $prefix = (string) ($definition['env_prefix'] ?? strtoupper($name));
            foreach (['API_KEY', 'BASE_URL', 'MODEL', ...(self::PROVIDER_OPTIONS[$name] ?? [])] as $suffix) {
                [$value, $source] = $this->value($prefix . '_' . $suffix);
                $rows[] = [$prefix . '_' . $suffix, $value, $source];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private static function processEnvironment(): array
    {
        return array_filter(getenv(), static fn (string $v): bool => $v !== '');
    }
}
