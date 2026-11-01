<?php

declare(strict_types=1);

namespace LaraInspect\AI\Exceptions;

/**
 * The provider rejected the request (HTTP 400/404/413/422 or similar); retrying will not help.
 */
final class RequestRejectedException extends AIException
{
}
