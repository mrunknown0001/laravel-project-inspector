<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

use LaraInspect\AI\Contracts\AIProviderInterface;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\AIException;
use LaraInspect\AI\Exceptions\AuthenticationException;
use LaraInspect\AI\Exceptions\ConfigurationException;
use LaraInspect\AI\Exceptions\InvalidResponseException;
use LaraInspect\AI\Exceptions\ProviderUnavailableException;
use LaraInspect\AI\Exceptions\RateLimitException;
use LaraInspect\AI\Exceptions\RequestRejectedException;
use LaraInspect\AI\Exceptions\TimeoutException;
use LaraInspect\AI\ProviderConfig;
use LaraInspect\Model\AuditEntry;
use LaraInspect\Process\AuditLog;
use LaraInspect\Security\SecretRedactor;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP plumbing shared by every provider: retries with backoff for rate limits,
 * overload and timeouts; typed exceptions; and one audit entry per request.
 * API keys are sent only in headers and never appear in messages or logs.
 */
abstract class AbstractHttpProvider implements AIProviderInterface
{
    private const MAX_RETRY_AFTER_SECONDS = 30;
    private const MAX_ERROR_LENGTH = 400;

    protected ?Usage $lastUsage = null;

    private readonly \Closure $sleep;

    private readonly SecretRedactor $redactor;

    /**
     * @param (\Closure(float): void)|null $sleep Injectable for tests (seconds).
     */
    public function __construct(
        protected readonly ProviderConfig $config,
        protected readonly HttpClientInterface $http,
        private readonly ?AuditLog $audit = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->redactor = new SecretRedactor($config->hasApiKey() ? [(string) $config->apiKey] : []);
    }

    public function getProviderName(): string
    {
        return $this->config->name;
    }

    public function getLastUsage(): ?Usage
    {
        return $this->lastUsage;
    }

    public function config(): ProviderConfig
    {
        return $this->config;
    }

    public function validateConfiguration(): array
    {
        $problems = [];
        $prefix = strtoupper($this->config->name);
        if (!$this->config->hasApiKey()) {
            $problems[] = sprintf('%s_API_KEY is not set.', $prefix);
        }
        $url = parse_url($this->config->baseUrl);
        if (!is_array($url) || !isset($url['scheme'], $url['host'])) {
            $problems[] = sprintf('%s_BASE_URL "%s" is not a valid URL.', $prefix, $this->config->baseUrl);
        } elseif ($url['scheme'] !== 'https' && !in_array($url['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            $problems[] = sprintf('%s_BASE_URL must use https:// (plain http is allowed only for localhost).', $prefix);
        }
        if ($this->config->model === null || trim($this->config->model) === '') {
            $problems[] = sprintf('No model is configured. Set %s_MODEL (or AI_MODEL), or pass --model. List models with "larainspect ai:models %s".', $prefix, $this->config->name);
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    protected function requireModel(?string $model): string
    {
        $model ??= $this->config->model;
        if ($model === null || trim($model) === '') {
            throw new ConfigurationException(sprintf('No model configured for %s. Set %s_MODEL or AI_MODEL, or pass --model.', $this->config->label, strtoupper($this->config->name)));
        }
        if (!$this->config->hasApiKey()) {
            throw new ConfigurationException(sprintf('%s_API_KEY is not set.', strtoupper($this->config->name)));
        }

        return $model;
    }

    /**
     * Sends a JSON request and returns the decoded JSON body.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $extraHeaders
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    protected function send(string $method, string $path, ?array $json, string $purpose, array $extraHeaders = []): array
    {
        $url = $this->config->baseUrl . $path;
        $attempt = 0;

        while (true) {
            $started = hrtime(true);
            try {
                $response = $this->http->request($method, $url, array_filter([
                    'headers' => $this->headers() + $extraHeaders,
                    'json' => $json,
                    'timeout' => $this->config->timeout,
                    'max_duration' => $this->config->timeout,
                ], static fn (mixed $v): bool => $v !== null));
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                $headers = $response->getHeaders(false);
                $error = $status >= 200 && $status < 300 ? null : $this->mapError($status, self::decodeOrNull($content), $headers);
            } catch (TimeoutExceptionInterface) {
                $status = null;
                $content = '';
                $error = new TimeoutException(sprintf('%s did not respond within %d seconds.', $this->config->label, (int) $this->config->timeout));
            } catch (TransportExceptionInterface $e) {
                $status = null;
                $content = '';
                $error = new ProviderUnavailableException(sprintf('Could not reach %s: %s', $this->config->label, $this->redactor->redact($e->getMessage())));
            }

            $this->audit($purpose, $method, $url, $json, $status, $error, $started);

            if ($error === null) {
                $decoded = self::decodeOrNull($content);
                if ($decoded === null) {
                    throw new InvalidResponseException(sprintf('%s returned a response that is not a JSON object.', $this->config->label));
                }

                return $decoded;
            }

            $retryable = $error instanceof RateLimitException || $error instanceof ProviderUnavailableException || $error instanceof TimeoutException;
            if (!$retryable || $attempt >= $this->config->maxRetries) {
                throw $error;
            }
            $attempt++;
            $wait = $error instanceof RateLimitException && $error->retryAfterSeconds !== null
                ? min(self::MAX_RETRY_AFTER_SECONDS, max(1, $error->retryAfterSeconds))
                : 2 ** ($attempt - 1);
            ($this->sleep)((float) $wait);
        }
    }

    /**
     * Best human-readable message in a provider error body.
     *
     * @param array<string, mixed>|null $body
     */
    protected function errorMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            $message = $error['message'] ?? null;
            $type = $error['type'] ?? ($error['code'] ?? null);

            return is_string($message) ? (is_scalar($type) ? sprintf('%s (%s)', $message, (string) $type) : $message) : null;
        }
        if (is_string($error)) {
            return $error;
        }

        return is_string($body['message'] ?? null) ? $body['message'] : null;
    }

    /**
     * @param array<string, mixed>|null       $body
     * @param array<string, list<string>>     $headers
     */
    protected function mapError(int $status, ?array $body, array $headers): AIException
    {
        $detail = $this->errorMessage($body) ?? 'no details';
        $detail = $this->redactor->redact(mb_substr($detail, 0, self::MAX_ERROR_LENGTH));
        $message = sprintf('%s API error (HTTP %d): %s', $this->config->label, $status, $detail);

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message),
            $status === 408 => new TimeoutException($message),
            $status === 429 => new RateLimitException($message, self::retryAfter($headers)),
            $status >= 500 => new ProviderUnavailableException($message),
            default => new RequestRejectedException($message),
        };
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return $value !== null && is_numeric($value) ? (int) ceil((float) $value) : null;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function audit(string $purpose, string $method, string $url, ?array $json, ?int $status, ?AIException $error, int|float $started): void
    {
        if ($this->audit === null) {
            return;
        }
        $model = is_array($json) && is_string($json['model'] ?? null) ? ' (model ' . $json['model'] . ')' : '';
        $this->audit->record(new AuditEntry(
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            sprintf('%s: %s', $this->config->label, $purpose),
            sprintf('%s %s%s', $method, $url, $model),
            '',
            match (true) {
                $error instanceof TimeoutException && $status === null => 'timed-out',
                $error !== null && $status === null => 'error',
                $error !== null => 'http-' . $status,
                default => 'completed',
            },
            $status,
            (int) ((hrtime(true) - $started) / 1_000_000),
            true,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decodeOrNull(string $content): ?array
    {
        if ($content === '') {
            return null;
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }
}
