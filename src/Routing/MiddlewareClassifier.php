<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

/**
 * Classifies a route's middleware stack by the access control it implies.
 *
 * Only well-known Laravel and package middleware are recognised as
 * authentication. Unknown custom middleware ("admin", "tenant") might enforce
 * access control, so such routes are reported as "custom" for manual review
 * instead of being called public.
 */
final class MiddlewareClassifier
{
    public const AUTHENTICATED = 'authenticated';
    public const SIGNED = 'signed';
    public const CUSTOM = 'custom';
    public const PUBLIC = 'public';

    /** Aliases (before ":") that require an authenticated user. */
    private const AUTH_ALIASES = ['auth', 'auth.basic', 'can', 'verified', 'password.confirm', 'role', 'permission', 'role_or_permission', 'abilities', 'ability', 'scopes', 'scope', 'client'];

    /** Class name fragments of authentication/authorization middleware. */
    private const AUTH_CLASS_FRAGMENTS = ['Authenticate', 'Authorize', 'EnsureEmailIsVerified', 'RequirePassword', 'CheckAbilities', 'CheckForAnyAbility', 'RoleMiddleware', 'PermissionMiddleware', 'CheckClientCredentials'];

    /** Middleware that neither grants nor restricts access. */
    private const NEUTRAL = [
        'web', 'api', 'guest', 'throttle', 'bindings', 'cache.headers', 'precognitive', 'cors', 'json', 'localization', 'locale',
        'setlocale', 'stateful', 'auth.session', 'signed', 'horizon', 'telescope', 'nova', 'universal', 'inertia',
        'HandleInertiaRequests', 'SubstituteBindings', 'ThrottleRequests', 'EnsureFrontendRequestsAreStateful',
        'HandlePrecognitiveRequests', 'SetCacheHeaders', 'ValidateSignature', 'AddLinkHeadersForPreloadedAssets',
        'ShareErrorsFromSession', 'StartSession', 'EncryptCookies', 'VerifyCsrfToken', 'ValidateCsrfToken', 'PreventRequestForgery',
        'AddQueuedCookiesToResponse', 'HandleCors', 'TrimStrings', 'ConvertEmptyStringsToNull', 'TrustProxies',
        'RedirectIfAuthenticated', 'AuthenticateSession',
    ];

    /**
     * @param list<string> $middleware
     */
    public static function access(array $middleware): string
    {
        $hasCustom = false;
        $signed = false;
        foreach ($middleware as $entry) {
            if (self::isAuth($entry)) {
                return self::AUTHENTICATED;
            }
            if (self::isSigned($entry)) {
                $signed = true;
            } elseif (!self::isNeutral($entry)) {
                $hasCustom = true;
            }
        }

        return match (true) {
            $signed => self::SIGNED,
            $hasCustom => self::CUSTOM,
            default => self::PUBLIC,
        };
    }

    public static function isAuth(string $middleware): bool
    {
        $alias = strtolower(explode(':', $middleware, 2)[0]);
        if (in_array($alias, self::AUTH_ALIASES, true)) {
            return true;
        }
        $class = self::classBase($middleware);
        foreach (self::AUTH_CLASS_FRAGMENTS as $fragment) {
            if ($class !== null && str_contains($class, $fragment) && !str_contains($class, 'RedirectIf') && $class !== 'AuthenticateSession') {
                return true;
            }
        }

        return false;
    }

    private static function isSigned(string $middleware): bool
    {
        $alias = strtolower(explode(':', $middleware, 2)[0]);

        return $alias === 'signed' || self::classBase($middleware) === 'ValidateSignature';
    }

    private static function isNeutral(string $middleware): bool
    {
        $alias = explode(':', $middleware, 2)[0];
        if (in_array(strtolower($alias), self::NEUTRAL, true)) {
            return true;
        }
        $class = self::classBase($middleware);

        return $class !== null && in_array($class, self::NEUTRAL, true);
    }

    /**
     * "App\Http\Middleware\Authenticate:sanctum" => "Authenticate"; null for aliases.
     */
    private static function classBase(string $middleware): ?string
    {
        $name = explode(':', $middleware, 2)[0];
        if (!str_contains($name, '\\')) {
            return null;
        }
        $parts = explode('\\', $name);

        return end($parts) ?: null;
    }
}
