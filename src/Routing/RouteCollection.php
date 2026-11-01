<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

final readonly class RouteCollection
{
    /**
     * @param list<RouteDefinition> $routes
     * @param list<string>          $files    Route files that were read.
     * @param list<string>          $notes    Caveats (dynamic includes, parse errors, fallbacks).
     */
    public function __construct(
        public array $routes,
        public string $source,
        public array $files = [],
        public array $notes = [],
    ) {
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * @return list<RouteDefinition>
     */
    public function filter(callable $predicate): array
    {
        return array_values(array_filter($this->routes, $predicate));
    }
}
