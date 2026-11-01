<?php

declare(strict_types=1);

namespace LaraInspect\Exception;

/**
 * The target directory does not exist, is not readable, or is not a Laravel project.
 */
final class InvalidProjectException extends InspectorException
{
}
