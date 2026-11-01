<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Process\CommandSpec;

/**
 * Chooses how routes are obtained: statically by default, or from
 * "php artisan route:list --json" when the user opted in and confirmed.
 */
final class RouteLoader
{
    public const ARTISAN_WARNING = 'This boots the Laravel application: its service providers run, it may connect to configured '
        . 'services (database, cache, queues), and it may write to storage/ and bootstrap/cache/ (logs, package manifest).';

    public function load(InspectionContext $context): RouteCollection
    {
        $fallbackNote = null;
        if ($context->options->useArtisan) {
            $artisan = $this->fromArtisan($context);
            if ($artisan instanceof RouteCollection) {
                return $artisan;
            }
            $fallbackNote = sprintf('artisan route:list was not used (%s); routes were read statically instead.', $artisan);
        }

        $routes = (new StaticRouteParser())->parse($context);

        return $fallbackNote === null ? $routes : new RouteCollection($routes->routes, $routes->source, $routes->files, [$fallbackNote, ...$routes->notes]);
    }

    private function fromArtisan(InspectionContext $context): RouteCollection|string
    {
        if (!$context->files->isFile('artisan')) {
            return 'the project has no artisan file';
        }

        $result = $context->processes->run(new CommandSpec(
            'php',
            ['artisan', 'route:list', '--json', '--no-ansi', '--no-interaction'],
            'List registered routes with artisan (boots the application)',
            $context->root(),
            $context->options->processTimeout,
            sideEffects: true,
            warning: self::ARTISAN_WARNING,
        ));
        if (!$result->isSuccessful()) {
            return $result->failureReason();
        }

        $start = strpos($result->output, '[');
        try {
            $rows = $start === false ? null : json_decode(substr($result->output, $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $rows = null;
        }
        if (!is_array($rows)) {
            return 'its output was not valid JSON';
        }

        $routes = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $routes[] = RouteDefinition::fromArtisan($row);
            }
        }

        return new RouteCollection($routes, RouteDefinition::SOURCE_ARTISAN, ['artisan route:list'], [
            'Routes were listed by "php artisan route:list", including routes registered by packages. Middleware names are as reported by Laravel.',
        ]);
    }
}
