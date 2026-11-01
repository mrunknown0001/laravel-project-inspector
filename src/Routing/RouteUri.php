<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

/**
 * URI helpers matching how Laravel stores route URIs ("api/users", "/" for the root).
 */
final class RouteUri
{
    public static function join(string ...$parts): string
    {
        $segments = array_filter(array_map(static fn (string $p): string => trim($p, '/'), $parts), static fn (string $p): bool => $p !== '');

        return $segments === [] ? '' : implode('/', $segments);
    }

    public static function normalise(string $uri): string
    {
        $uri = trim($uri, '/');

        return $uri === '' ? '/' : $uri;
    }

    /**
     * Approximation of Str::singular for resource parameter names.
     */
    public static function singular(string $word): string
    {
        $word = str_replace('-', '_', $word);

        return match (true) {
            str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            str_ends_with($word, 'sses'), str_ends_with($word, 'shes'), str_ends_with($word, 'ches'), str_ends_with($word, 'xes') => substr($word, 0, -2),
            str_ends_with($word, 'ss'), str_ends_with($word, 'us'), str_ends_with($word, 'is') => $word,
            str_ends_with($word, 's') => substr($word, 0, -1),
            default => $word,
        };
    }
}
