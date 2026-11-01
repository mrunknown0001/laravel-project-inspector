<?php

declare(strict_types=1);

namespace LaraInspect\Exception;

/**
 * A path resolved outside the inspected project, or was otherwise unsafe to access.
 */
final class UnsafePathException extends InspectorException
{
}
