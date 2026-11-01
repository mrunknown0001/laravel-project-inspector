<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

/**
 * Source rules receive a display label ("app/X.php (not tracked by git)");
 * this recovers the plain path for extension and directory checks.
 */
final class SourceFile
{
    public const UNTRACKED_SUFFIX = ' (not tracked by git)';

    public static function path(string $label): string
    {
        return str_ends_with($label, self::UNTRACKED_SUFFIX) ? substr($label, 0, -strlen(self::UNTRACKED_SUFFIX)) : $label;
    }
}
