<?php

declare(strict_types=1);

namespace LaraInspect\AI\Exceptions;

/**
 * The provider is overloaded or failing (HTTP 5xx/529); retrying later may help.
 */
final class ProviderUnavailableException extends AIException
{
}
