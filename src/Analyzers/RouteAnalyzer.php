<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Routing\MiddlewareClassifier;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Routing\RouteRiskClassifier;
use LaraInspect\Support\Evidence;

/**
 * Route inventory: methods, names, middleware, controllers and access control.
 *
 * Security findings about routes are produced by the security analyzer; this
 * analyzer reports the inventory, the review list and structural problems.
 */
final class RouteAnalyzer implements AnalyzerInterface
{
    /** Packages that register their own routes at runtime. */
    private const ROUTE_PACKAGES = [
        'laravel/telescope' => 'Telescope', 'laravel/horizon' => 'Horizon', 'laravel/sanctum' => 'Sanctum',
        'laravel/fortify' => 'Fortify', 'laravel/jetstream' => 'Jetstream', 'laravel/passport' => 'Passport',
        'laravel/nova' => 'Nova', 'laravel/pulse' => 'Pulse', 'laravel/cashier' => 'Cashier',
        'filament/filament' => 'Filament', 'livewire/livewire' => 'Livewire', 'barryvdh/laravel-debugbar' => 'Debugbar',
        'opcodesio/log-viewer' => 'Log Viewer', 'spatie/laravel-health' => 'Laravel Health',
    ];

    public function name(): string
    {
        return 'routes';
    }

    public function title(): string
    {
        return 'Routes';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $collection = $context->routes();
        $routes = $collection->routes;
        $source = $collection->source === RouteDefinition::SOURCE_ARTISAN ? Source::Detected : Source::Inferred;
        $origin = $collection->source === RouteDefinition::SOURCE_ARTISAN ? 'php artisan route:list' : 'static analysis of route files';

        $access = array_fill_keys([MiddlewareClassifier::AUTHENTICATED, MiddlewareClassifier::SIGNED, MiddlewareClassifier::CUSTOM, MiddlewareClassifier::PUBLIC], 0);
        $methods = [];
        $controllers = [];
        foreach ($routes as $route) {
            $access[$route->access()]++;
            foreach ($route->methods as $method) {
                if ($method !== 'HEAD') {
                    $methods[$method] = ($methods[$method] ?? 0) + 1;
                }
            }
            if ($route->controller !== null) {
                $controllers[$route->controller] = true;
            }
        }
        ksort($methods);

        $api = count(array_filter($routes, static fn (RouteDefinition $r): bool => $r->isApi()));
        $facts = [
            new Fact('Route source', $collection->source === RouteDefinition::SOURCE_ARTISAN ? 'artisan route:list' : 'Static analysis (application not booted)', Source::Detected, null),
            new Fact('Route files read', $collection->files === [] ? 'None' : implode(', ', $collection->files), Source::Detected, null),
            new Fact('Total routes', (string) count($routes), $source, $origin),
            new Fact('Web routes', (string) (count($routes) - $api), $source, $origin),
            new Fact('API routes', (string) $api, $source, $origin),
            new Fact('Named routes', (string) count(array_filter($routes, static fn (RouteDefinition $r): bool => $r->name !== null)), $source, $origin),
            new Fact('Closure routes', (string) count(array_filter($routes, static fn (RouteDefinition $r): bool => $r->isClosure)), $source, $origin),
            new Fact('Controllers referenced', (string) count($controllers), $source, $origin),
            new Fact('Behind authentication middleware', (string) $access[MiddlewareClassifier::AUTHENTICATED], $source, $origin),
            new Fact('Signed-URL routes', (string) $access[MiddlewareClassifier::SIGNED], $source, $origin),
            new Fact('Behind custom middleware only', (string) $access[MiddlewareClassifier::CUSTOM], $source, 'middleware not recognised as authentication; may still restrict access'),
            new Fact('Without authentication middleware', (string) $access[MiddlewareClassifier::PUBLIC], $source, $origin),
        ];

        $sorted = $routes;
        usort($sorted, static fn (RouteDefinition $a, RouteDefinition $b): int => [$a->uri, $a->methodLabel()] <=> [$b->uri, $b->methodLabel()]);

        $review = [];
        foreach ($sorted as $route) {
            foreach (RouteRiskClassifier::reviewReasons($route) as $reason) {
                $review[] = [$route->methodLabel(), $route->uri, $route->access(), $reason, $this->where($route)];
            }
        }

        $tables = [
            new Table('Routes by HTTP method', ['Method', 'Routes'], array_map(static fn (string $m, int $n): array => [$m, (string) $n], array_keys($methods), array_values($methods))),
            new Table('Routes requiring review', ['Method', 'URI', 'Access', 'Reason', 'Defined in'], $review),
            new Table('Route list', ['Method', 'URI', 'Name', 'Action', 'Middleware', 'Access'], array_map(static fn (RouteDefinition $r): array => [
                $r->methodLabel(),
                $r->uri,
                $r->name ?? '',
                $r->shortAction(),
                implode(', ', $r->middleware),
                $r->access(),
            ], $sorted)),
        ];

        $notes = $collection->notes;
        if ($collection->source === RouteDefinition::SOURCE_STATIC) {
            $packages = [];
            foreach (self::ROUTE_PACKAGES as $package => $label) {
                if ($context->composer()->lockedPackage($package) !== null || isset($context->composer()->require()[$package])) {
                    $packages[] = $label;
                }
            }
            if ($packages !== []) {
                $notes[] = sprintf('Installed packages that register their own routes (not listed above): %s.', implode(', ', $packages));
            }
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: [...$this->duplicateFindings($routes), ...$this->missingControllerFindings($routes)],
            notes: $notes,
            data: [
                'source' => $collection->source,
                'counts' => ['total' => count($routes), 'api' => $api, 'web' => count($routes) - $api, 'by_method' => $methods, 'by_access' => $access],
                'routes' => array_map(static fn (RouteDefinition $r): array => $r->toArray(), $sorted),
            ],
        );
    }

    private function where(RouteDefinition $route): string
    {
        return $route->line > 0 ? sprintf('%s:%d', $route->file, $route->line) : $route->file;
    }

    /**
     * @param list<RouteDefinition> $routes
     * @return list<Finding>
     */
    private function duplicateFindings(array $routes): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($routes as $route) {
            if ($route->source !== RouteDefinition::SOURCE_STATIC || str_contains($route->uri, '{dynamic}')) {
                continue;
            }
            foreach ($route->methods as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $key = ($route->domain ?? '') . ' ' . $method . ' ' . $route->uri;
                if (isset($seen[$key])) {
                    $duplicates[] = sprintf('%s %s: %s overrides %s', $method, $route->uri, $this->where($route), $seen[$key]);
                } else {
                    $seen[$key] = $this->where($route);
                }
            }
        }
        if ($duplicates === []) {
            return [];
        }

        return [new Finding(
            'routes.duplicate',
            sprintf('%s registered more than once', Evidence::plural(count($duplicates), 'route is', 'routes are')),
            Severity::Low,
            Confidence::Confirmed,
            'routes/',
            'When two routes share a method and URI, the later definition silently replaces the earlier one, so one of them is unreachable.',
            Evidence::list($duplicates),
            'Remove or rename one of each pair of duplicate routes.',
        )];
    }

    /**
     * @param list<RouteDefinition> $routes
     * @return list<Finding>
     */
    private function missingControllerFindings(array $routes): array
    {
        $missing = [];
        foreach ($routes as $route) {
            if ($route->controllerMissing && $route->controller !== null) {
                $missing[$route->controller] = sprintf('%s (used by %s %s, %s)', $route->controller, $route->methodLabel(), $route->uri, $this->where($route));
            }
        }
        if ($missing === []) {
            return [];
        }

        return [new Finding(
            'routes.missing-controller',
            sprintf('%s referenced by routes could not be found', Evidence::plural(count($missing), 'controller')),
            Severity::Low,
            Confidence::Potential,
            'routes/',
            'The controller class does not exist at the path its namespace maps to under composer.json PSR-4 rules. Requests to these routes would fail, unless the class is loaded some other way (classmap, custom autoloader).',
            Evidence::list(array_values($missing)),
            'Create the controller, fix the namespace in the route definition, or remove the route.',
        )];
    }
}
