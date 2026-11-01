<?php

declare(strict_types=1);

namespace LaraInspect\AI;

use LaraInspect\AI\Contracts\AIProviderInterface;
use LaraInspect\AI\Exceptions\ConfigurationException;
use LaraInspect\AI\Providers\AbstractHttpProvider;
use LaraInspect\Process\AuditLog;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates provider adapters from configuration.
 *
 * New providers are added in config/providers.php with a class that extends
 * AbstractHttpProvider (or implements AIProviderInterface with the same
 * constructor); nothing here needs to change.
 */
final class AIManager
{
    private readonly HttpClientInterface $http;

    /**
     * @param (\Closure(float): void)|null $sleep Retry delay hook (tests).
     */
    public function __construct(
        private readonly AIConfig $config,
        ?HttpClientInterface $http = null,
        private readonly ?AuditLog $audit = null,
        private readonly ?\Closure $sleep = null,
    ) {
        $this->http = $http ?? HttpClient::create(['headers' => ['User-Agent' => 'laravel-project-inspector']]);
    }

    public function config(): AIConfig
    {
        return $this->config;
    }

    /**
     * @return list<string>
     */
    public function providerNames(): array
    {
        return $this->config->providerNames();
    }

    /**
     * The provider to use: an explicit --provider, else AI_PROVIDER.
     *
     * @throws ConfigurationException
     */
    public function resolveName(?string $requested): string
    {
        $name = $requested !== null && $requested !== '' ? strtolower($requested) : $this->config->defaultProvider();
        if ($name === null) {
            throw new ConfigurationException(sprintf(
                'No AI provider selected. Set AI_PROVIDER in the environment or the inspector\'s .env, or pass --provider (%s).',
                implode(', ', $this->providerNames()),
            ));
        }
        $this->config->definition($name);

        return $name;
    }

    /**
     * @throws ConfigurationException
     */
    public function provider(?string $name = null, ?string $model = null): AIProviderInterface
    {
        $name = $this->resolveName($name);
        $class = $this->config->definition($name)['class'] ?? null;
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, AIProviderInterface::class)) {
            throw new ConfigurationException(sprintf('Provider "%s" has no valid adapter class in config/providers.php.', $name));
        }
        if (!is_subclass_of($class, AbstractHttpProvider::class)) {
            throw new ConfigurationException(sprintf('Adapter %s must extend %s.', $class, AbstractHttpProvider::class));
        }

        return new $class($this->config->provider($name, $model), $this->http, $this->audit, $this->sleep);
    }
}
