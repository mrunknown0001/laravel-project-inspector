<?php

declare(strict_types=1);

namespace LaraInspect\AI\Exceptions;

/**
 * The provider rate-limited the request (HTTP 429).
 */
final class RateLimitException extends AIException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
