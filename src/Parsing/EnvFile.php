<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

/**
 * A privacy-preserving view of a project's .env file.
 *
 * Only values of keys on the safe allowlist are retained. For every other key
 * the inspector only knows whether it is defined and whether it is empty.
 * Values of sensitive keys are kept separately, in memory only, so the
 * redactor can scrub them from any output by exact match.
 */
final class EnvFile
{
    /**
     * Keys whose values are configuration choices, not credentials.
     */
    public const SAFE_KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_TIMEZONE',
        'APP_MAINTENANCE_DRIVER', 'BCRYPT_ROUNDS',
        'DB_CONNECTION',
        'CACHE_DRIVER', 'CACHE_STORE',
        'QUEUE_CONNECTION', 'QUEUE_DRIVER',
        'SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE', 'SESSION_HTTP_ONLY',
        'SESSION_EXPIRE_ON_CLOSE', 'SESSION_DOMAIN', 'QUEUE_FAILED_DRIVER', 'AUTH_GUARD', 'AUTH_PASSWORD_TIMEOUT', 'HASH_DRIVER',
        'LOG_CHANNEL', 'LOG_STACK', 'LOG_LEVEL', 'LOG_DEPRECATIONS_CHANNEL',
        'FILESYSTEM_DISK', 'FILESYSTEM_DRIVER',
        'BROADCAST_DRIVER', 'BROADCAST_CONNECTION',
        'MAIL_MAILER', 'MAIL_DRIVER',
        'TELESCOPE_ENABLED', 'DEBUGBAR_ENABLED',
    ];

    /**
     * @param array<string, string> $safeValues
     * @param array<string, bool>   $defined     key => non-empty
     * @param list<string>          $secretValues
     * @param array<string, string> $hashes      key => sha256(value) for non-empty values
     */
    private function __construct(
        private readonly string $origin,
        private readonly array $safeValues,
        private readonly array $defined,
        private readonly array $secretValues,
        private readonly array $hashes = [],
    ) {
    }

    public static function fromContents(string $contents, string $origin, ?EnvFileParser $parser = null): self
    {
        $raw = ($parser ?? new EnvFileParser())->parse($contents);

        $safe = [];
        $defined = [];
        $secrets = [];
        $hashes = [];
        foreach ($raw as $key => $value) {
            $defined[$key] = $value !== '';
            if ($value !== '') {
                $hashes[$key] = hash('sha256', $value);
            }
            if (in_array($key, self::SAFE_KEYS, true)) {
                $safe[$key] = $value;
            } elseif ($value !== '') {
                $secrets[] = $value;
            }
        }

        return new self($origin, $safe, $defined, $secrets, $hashes);
    }

    public static function empty(): self
    {
        return new self('', [], [], []);
    }

    /**
     * File the values came from (".env" or ".env.example"); empty when none was found.
     */
    public function origin(): string
    {
        return $this->origin;
    }

    public function exists(): bool
    {
        return $this->origin !== '';
    }

    public function isExample(): bool
    {
        return $this->origin !== '' && $this->origin !== '.env';
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->defined);
    }

    public function isFilled(string $key): bool
    {
        return $this->defined[$key] ?? false;
    }

    /**
     * Returns the raw value of an allowlisted key; null if unset or not allowlisted.
     */
    public function get(string $key): ?string
    {
        return $this->safeValues[$key] ?? null;
    }

    /**
     * SHA-256 of a value, so two env files can be compared without exposing either value.
     */
    public function valueHash(string $key): ?string
    {
        return $this->hashes[$key] ?? null;
    }

    public function isReadable(string $key): bool
    {
        return array_key_exists($key, $this->safeValues);
    }

    /**
     * Interprets a value the way Laravel's env() helper does.
     */
    public function typed(string $key): string|bool|null
    {
        $value = $this->get($key);
        if ($value === null) {
            return null;
        }

        return self::castLikeLaravel($value);
    }

    public static function castLikeLaravel(string $value): string|bool|null
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /**
     * @return list<string> All defined key names (never values).
     */
    public function keys(): array
    {
        return array_keys($this->defined);
    }

    /**
     * Values of non-allowlisted keys, for exact-match redaction only. Never output these.
     *
     * @return list<string>
     */
    public function secretValuesForRedaction(): array
    {
        return $this->secretValues;
    }
}
