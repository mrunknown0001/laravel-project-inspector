<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

/**
 * Heuristics for routes that deserve a manual access-control review.
 *
 * A public route is not a problem by itself; only public routes whose path
 * suggests privileged functionality, or that change state on the API, are
 * listed. Routes behind unrecognised custom middleware are never flagged,
 * because that middleware may well enforce access control.
 */
final class RouteRiskClassifier
{
    /** Path segments that usually indicate privileged or sensitive functionality. */
    private const SENSITIVE_SEGMENTS = [
        'admin', 'administrator', 'backend', 'dashboard', 'manage', 'management', 'settings', 'config', 'configuration',
        'users', 'accounts', 'account', 'profile', 'billing', 'payments', 'payment', 'invoices', 'export', 'exports',
        'import', 'backup', 'backups', 'debug', 'phpinfo', 'logs', 'env', 'install', 'setup', 'migrate', 'artisan',
        'impersonate', 'internal', 'private', 'telescope', 'horizon', 'log-viewer', '_debugbar', 'pulse', 'clockwork',
    ];

    /** Endpoints that are public by design. */
    private const PUBLIC_BY_DESIGN = '#^(?:api/)?(?:login|logout|register|forgot-password|reset-password(?:/.*)?|password(?:/.*)?|'
        . 'email/verify(?:/.*)?|verify-email(?:/.*)?|confirm-password|two-factor-challenge|sanctum/csrf-cookie|oauth/(?:token|authorize).*|'
        . 'auth/[^/]+/(?:redirect|callback)|up|health|webhooks?(?:/.*)?|stripe/webhook)$#i';

    /** Calls inside a closure route that are dangerous to expose over HTTP. */
    public const DANGEROUS_CALLS = [
        'phpinfo' => 'exposes PHP configuration and environment variables',
        'eval' => 'evaluates PHP code',
        'exec' => 'runs shell commands',
        'shell_exec' => 'runs shell commands',
        'system' => 'runs shell commands',
        'passthru' => 'runs shell commands',
        'proc_open' => 'runs shell commands',
        'popen' => 'runs shell commands',
        'Artisan::call' => 'runs Artisan commands',
        'Artisan::queue' => 'runs Artisan commands',
        'DB::statement' => 'runs raw SQL',
        'DB::unprepared' => 'runs raw SQL',
        'Schema::drop' => 'drops database tables',
        'Schema::dropIfExists' => 'drops database tables',
        'unlink' => 'deletes files',
        'file_put_contents' => 'writes files',
        'dd' => 'dumps application state',
        'dump' => 'dumps application state',
        'var_dump' => 'dumps application state',
    ];

    /**
     * Reasons a route needs review; empty when it does not.
     *
     * @return list<string>
     */
    public static function reviewReasons(RouteDefinition $route): array
    {
        $access = $route->access();
        $reasons = [];

        $dangerous = self::dangerousCalls($route);
        foreach ($dangerous as $call => $description) {
            $reasons[] = sprintf('closure calls %s() (%s)', $call, $description);
        }

        if ($access !== MiddlewareClassifier::PUBLIC || self::isPublicByDesign($route)) {
            return $reasons;
        }

        $segment = self::sensitiveSegment($route->uri);
        if ($segment !== null) {
            $reasons[] = sprintf('path segment "%s" suggests privileged functionality, but no authentication middleware was found', $segment);
        }
        if ($route->isApi() && $route->isStateChanging()) {
            $reasons[] = sprintf('%s API endpoint without authentication middleware', $route->methodLabel());
        }

        return $reasons;
    }

    /**
     * @return array<string, string> call => description
     */
    public static function dangerousCalls(RouteDefinition $route): array
    {
        $found = [];
        foreach ($route->closureCalls as $call) {
            if (isset(self::DANGEROUS_CALLS[$call])) {
                $found[$call] = self::DANGEROUS_CALLS[$call];
            }
        }

        return $found;
    }

    public static function sensitiveSegment(string $uri): ?string
    {
        foreach (explode('/', strtolower($uri)) as $segment) {
            if (in_array($segment, self::SENSITIVE_SEGMENTS, true)) {
                return $segment;
            }
        }

        return null;
    }

    public static function isPublicByDesign(RouteDefinition $route): bool
    {
        return preg_match(self::PUBLIC_BY_DESIGN, $route->uri) === 1;
    }
}
