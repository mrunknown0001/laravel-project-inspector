<?php

declare(strict_types=1);

namespace LaraInspect\Model;

/**
 * Where a fact came from.
 *
 * Detected: read directly (a lock file entry, a literal value, a .env value).
 * Inferred: derived indirectly (a constraint, an env() default, .env.example).
 * Unknown:  could not be determined without executing project code.
 */
enum Source: string
{
    case Detected = 'detected';
    case Inferred = 'inferred';
    case Unknown = 'unknown';
}
