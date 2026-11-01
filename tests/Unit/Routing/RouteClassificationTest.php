<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Routing;

use LaraInspect\Routing\MiddlewareClassifier;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Routing\RouteRiskClassifier;
use LaraInspect\Routing\RouteUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteClassificationTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function middlewareStacks(): iterable
    {
        yield 'auth alias' => [['web', 'auth'], MiddlewareClassifier::AUTHENTICATED];
        yield 'sanctum guard' => [['api', 'auth:sanctum'], MiddlewareClassifier::AUTHENTICATED];
        yield 'can' => [['web', 'can:update,post'], MiddlewareClassifier::AUTHENTICATED];
        yield 'spatie role' => [['role:admin'], MiddlewareClassifier::AUTHENTICATED];
        yield 'class name' => [['web', 'App\\Http\\Middleware\\Authenticate'], MiddlewareClassifier::AUTHENTICATED];
        yield 'telescope gate' => [['web', 'Laravel\\Telescope\\Http\\Middleware\\Authorize'], MiddlewareClassifier::AUTHENTICATED];
        yield 'signed' => [['web', 'signed'], MiddlewareClassifier::SIGNED];
        yield 'custom' => [['web', 'admin'], MiddlewareClassifier::CUSTOM];
        yield 'guest only' => [['web', 'guest', 'throttle:6,1'], MiddlewareClassifier::PUBLIC];
        yield 'redirect-if-authenticated is not auth' => [['web', 'App\\Http\\Middleware\\RedirectIfAuthenticated'], MiddlewareClassifier::PUBLIC];
        yield 'none' => [[], MiddlewareClassifier::PUBLIC];
    }

    /**
     * @param list<string> $middleware
     */
    #[DataProvider('middlewareStacks')]
    public function testClassifiesAccess(array $middleware, string $expected): void
    {
        self::assertSame($expected, MiddlewareClassifier::access($middleware));
    }

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     * @param list<string> $calls
     */
    private static function route(array $methods, string $uri, array $middleware, array $calls = []): RouteDefinition
    {
        return new RouteDefinition($methods, $uri, null, $calls === [] ? 'X@y' : 'Closure', $middleware, 'routes/web.php', 3, closureCalls: $calls, isClosure: $calls !== []);
    }

    public function testReviewReasonsOnlyForPublicSensitiveOrWritingApiRoutes(): void
    {
        self::assertNotSame([], RouteRiskClassifier::reviewReasons(self::route(['GET'], 'admin/users', ['web'])));
        self::assertSame([], RouteRiskClassifier::reviewReasons(self::route(['GET'], 'admin/users', ['web', 'auth'])));
        self::assertSame([], RouteRiskClassifier::reviewReasons(self::route(['GET'], 'admin/users', ['web', 'admin'])), 'Custom middleware may protect it.');
        self::assertSame([], RouteRiskClassifier::reviewReasons(self::route(['GET'], 'blog/posts', ['web'])), 'Ordinary public pages are fine.');
        self::assertSame([], RouteRiskClassifier::reviewReasons(self::route(['POST'], 'api/login', ['api'])), 'Login is public by design.');
        self::assertSame([], RouteRiskClassifier::reviewReasons(self::route(['POST'], 'api/webhooks/stripe', ['api'])));
        self::assertStringContainsString('API endpoint', RouteRiskClassifier::reviewReasons(self::route(['DELETE'], 'api/posts/{post}', ['api']))[0]);
        self::assertStringContainsString('phpinfo', RouteRiskClassifier::reviewReasons(self::route(['GET'], 'info', ['web', 'auth'], ['phpinfo']))[0], 'Dangerous calls are listed even behind auth.');
    }

    public function testRouteDefinitionHelpers(): void
    {
        $route = self::route(['PUT', 'PATCH'], 'api/x', ['api']);

        self::assertSame('PUT|PATCH', $route->methodLabel());
        self::assertTrue($route->isStateChanging());
        self::assertTrue($route->isApi());
        self::assertSame('GET', self::route(['GET', 'HEAD'], '/', [])->methodLabel());

        $artisan = RouteDefinition::fromArtisan(['method' => 'GET|HEAD', 'uri' => 'admin', 'name' => '', 'action' => 'App\\Http\\Controllers\\AdminController@index', 'middleware' => "web\nauth"]);
        self::assertSame(['GET', 'HEAD'], $artisan->methods);
        self::assertSame(['web', 'auth'], $artisan->middleware);
        self::assertSame('index', $artisan->controllerMethod);
        self::assertNull($artisan->name);
        self::assertSame(RouteDefinition::SOURCE_ARTISAN, $artisan->source);
    }

    public function testUriHelpers(): void
    {
        self::assertSame('api/v1/users', RouteUri::join('/api/', 'v1', '/users/'));
        self::assertSame('/', RouteUri::normalise(''));
        self::assertSame('category', RouteUri::singular('categories'));
        self::assertSame('address', RouteUri::singular('addresses'));
        self::assertSame('photo_comment', RouteUri::singular('photo-comments'));
        self::assertSame('status', RouteUri::singular('status'));
    }
}
