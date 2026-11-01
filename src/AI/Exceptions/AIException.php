<?php

declare(strict_types=1);

namespace LaraInspect\AI\Exceptions;

/**
 * Base class for AI provider errors. Messages never contain API keys.
 */
class AIException extends \LaraInspect\Exception\InspectorException
{
}
