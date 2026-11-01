<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Routing\MiddlewareClassifier;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Routing\RouteRiskClassifier;
use LaraInspect\Support\Evidence;

/**
 * Routes that are reachable without authentication and look sensitive,
 * change state on the API, or execute dangerous code in a closure.
 */
final class RouteSecurityRule implements ProjectRuleInterface
{
    public function check(InspectionContext $context): array
    {
        $routes = $context->routes()->routes;
        $findings = [];

        $phpinfo = [];
        $dangerousPublic = [];
        $dangerousAuthenticated = [];
        $sensitive = [];
        $stateChanging = [];

        foreach ($routes as $route) {
            $where = self::where($route);
            $public = $route->access() === MiddlewareClassifier::PUBLIC;
            foreach (RouteRiskClassifier::dangerousCalls($route) as $call => $description) {
                $line = sprintf('%s %s calls %s() (%s), %s', $route->methodLabel(), $route->uri, $call, $description, $where);
                if ($call === 'phpinfo' && $public) {
                    $phpinfo[] = $line;
                } elseif ($public) {
                    $dangerousPublic[] = $line;
                } else {
                    $dangerousAuthenticated[] = $line . ' [' . $route->access() . ']';
                }
            }

            // Routes already reported for dangerous calls are not repeated as "sensitive".
            if (!$public || RouteRiskClassifier::isPublicByDesign($route) || RouteRiskClassifier::dangerousCalls($route) !== []) {
                continue;
            }
            $segment = RouteRiskClassifier::sensitiveSegment($route->uri);
            if ($segment !== null) {
                $sensitive[] = sprintf('%s %s ("%s"; middleware: %s), %s', $route->methodLabel(), $route->uri, $segment, $route->middleware === [] ? 'none' : implode(', ', $route->middleware), $where);
            } elseif ($route->isApi() && $route->isStateChanging()) {
                $stateChanging[] = sprintf('%s %s -> %s, %s', $route->methodLabel(), $route->uri, $route->shortAction(), $where);
            }
        }

        if ($phpinfo !== []) {
            $findings[] = new Finding(
                'sec.route-phpinfo',
                'A public route calls phpinfo()',
                Severity::High,
                Confidence::Confirmed,
                'routes/',
                'phpinfo() prints the PHP configuration, server paths and all environment variables, which in Laravel include database passwords and APP_KEY.',
                Evidence::list($phpinfo),
                'Remove the route. If PHP configuration must be inspected, do it from the command line.',
            );
        }
        if ($dangerousPublic !== []) {
            $findings[] = new Finding(
                'sec.route-dangerous-closure',
                sprintf('%s dangerous operations without authentication', Evidence::plural(count($dangerousPublic), 'route closure runs', 'route closures run')),
                Severity::High,
                Confidence::Potential,
                'routes/',
                'These closures run commands, raw SQL, file writes or debug dumps, and no authentication middleware was found on the route. Anyone who can reach the URL can trigger them.',
                Evidence::list($dangerousPublic),
                'Remove debug and maintenance routes, or move the logic to Artisan commands. If they must stay, protect them with authentication and authorization middleware.',
            );
        }
        if ($dangerousAuthenticated !== []) {
            $findings[] = new Finding(
                'sec.route-dangerous-closure-authenticated',
                sprintf('%s dangerous operations', Evidence::plural(count($dangerousAuthenticated), 'protected route closure runs', 'protected route closures run')),
                Severity::Medium,
                Confidence::Potential,
                'routes/',
                'These routes are behind middleware, but running commands or raw SQL from an HTTP request is risky. Every authenticated user, or a CSRF attack against one, could trigger them.',
                Evidence::list($dangerousAuthenticated),
                'Move the operation to an Artisan command or a queued job, and restrict the route to administrators.',
            );
        }
        if ($sensitive !== []) {
            $findings[] = new Finding(
                'sec.route-sensitive-public',
                sprintf('%s no authentication middleware', Evidence::plural(count($sensitive), 'route with a sensitive-looking path has', 'routes with sensitive-looking paths have')),
                Severity::Medium,
                Confidence::Potential,
                'routes/',
                'The path suggests privileged functionality (admin, settings, export, ...) and no authentication middleware was found at the route, group or controller level. The controller may still check access itself; verify each one.',
                Evidence::list($sensitive),
                'Add auth middleware (and authorization such as can: or policies) to the route group, or confirm the endpoint is intentionally public.',
            );
        }
        if ($stateChanging !== []) {
            $findings[] = new Finding(
                'sec.route-api-write-public',
                sprintf('%s data without authentication middleware', Evidence::plural(count($stateChanging), 'API endpoint changes', 'API endpoints change')),
                Severity::Low,
                Confidence::Potential,
                'routes/api.php',
                'POST/PUT/PATCH/DELETE API routes without authentication middleware can be called by anyone. This is sometimes intended (sign-up, contact forms, webhooks with signature checks), so review each route.',
                Evidence::list($stateChanging),
                'Protect the routes with auth:sanctum (or another guard), or document why they are public and how they are protected (signatures, rate limiting).',
            );
        }

        return $findings;
    }

    private static function where(RouteDefinition $route): string
    {
        return $route->line > 0 ? sprintf('%s:%d', $route->file, $route->line) : $route->file;
    }
}
