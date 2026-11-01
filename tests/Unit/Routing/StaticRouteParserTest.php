<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Routing;

use LaraInspect\Routing\RouteCollection;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Routing\StaticRouteParser;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class StaticRouteParserTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const COMPOSER = '{"require": {"laravel/framework": "^11.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}';

    private function parse(string $root): RouteCollection
    {
        return (new StaticRouteParser())->parse(Fixtures::context($root));
    }

    private static function route(RouteCollection $routes, string $method, string $uri): RouteDefinition
    {
        foreach ($routes->routes as $route) {
            if ($route->uri === $uri && in_array($method, $route->methods, true)) {
                return $route;
            }
        }
        self::fail(sprintf('Route %s %s not found. Have: %s', $method, $uri, implode(', ', array_map(static fn ($r) => $r->methodLabel() . ' ' . $r->uri, $routes->routes))));
    }

    /**
     * @param array<string, string> $files
     */
    private function project(array $files): string
    {
        return $this->makeProject($files + ['artisan' => '<?php', 'composer.json' => self::COMPOSER]);
    }

    public function testLegacyRouteServiceProviderWithNamespacesAndIncludes(): void
    {
        $routes = $this->parse(Fixtures::project('laravel-8'));

        self::assertSame(['app/Providers/RouteServiceProvider.php', 'routes/api.php', 'routes/web.php', 'routes/auth.php'], $routes->files);
        self::assertCount(11, $routes->routes);

        $legacy = self::route($routes, 'GET', 'legacy');
        self::assertSame('App\\Http\\Controllers\\LegacyController@show', $legacy->action, 'String actions get the provider $namespace.');
        self::assertFalse($legacy->controllerMissing);

        $login = self::route($routes, 'POST', 'login');
        self::assertSame('routes/auth.php', $login->file, 'require __DIR__.\'/auth.php\' is followed.');
        self::assertSame(['web', 'guest'], $login->middleware);

        $api = self::route($routes, 'GET', 'api/user');
        self::assertSame(['api', 'auth:sanctum'], $api->middleware);

        $reports = self::route($routes, 'GET', 'admin/reports');
        self::assertSame('admin.reports', $reports->name, 'Array group "as" prefixes names.');
        self::assertSame(['web', 'auth', 'can:admin'], $reports->middleware);
    }

    public function testResourcesAndControllerConstructorMiddleware(): void
    {
        $routes = $this->parse(Fixtures::project('laravel-8'));

        self::assertSame(['photos.index', 'photos.store', 'photos.show'], array_values(array_filter(array_map(static fn ($r) => $r->name, $routes->routes), static fn ($n) => $n !== null && str_starts_with($n, 'photos.'))));
        self::assertSame(['web'], self::route($routes, 'GET', 'photos')->middleware);
        self::assertSame(['web', 'auth'], self::route($routes, 'POST', 'photos')->middleware, '$this->middleware(\'auth\')->only([\'store\'])');
        self::assertSame('photos/{photo}', self::route($routes, 'GET', 'photos/{photo}')->uri);

        // DashboardController: auth everywhere, verified except index.
        self::assertSame(['web', 'auth'], self::route($routes, 'GET', 'dashboard')->middleware);
    }

    public function testModernWithRoutingControllerGroupsAndHasMiddleware(): void
    {
        $routes = $this->parse(Fixtures::project('laravel-11'));

        self::assertSame(['bootstrap/app.php', 'routes/web.php', 'routes/api.php'], $routes->files);

        $profile = self::route($routes, 'PATCH', 'profile');
        self::assertSame('App\\Http\\Controllers\\ProfileController@update', $profile->action);
        self::assertSame('profile.update', $profile->name);
        self::assertSame(['web', 'auth', 'verified'], $profile->middleware);

        $order = self::route($routes, 'GET', 'orders/{order}');
        self::assertSame(['web', 'auth', 'can:view,order'], $order->middleware, 'static middleware(): array on the controller');

        self::assertSame(['GET', 'HEAD'], self::route($routes, 'GET', '/')->methods);
        self::assertSame('View: welcome', self::route($routes, 'GET', '/')->action);
        self::assertSame(['ANY'], self::route($routes, 'ANY', 'home')->methods);
        self::assertSame('Health check (framework)', self::route($routes, 'GET', 'up')->action);
        self::assertSame('App\\Livewire\\Counter', self::route($routes, 'GET', 'counter')->controller);

        self::assertSame(['api', 'auth:sanctum'], self::route($routes, 'PUT', 'api/posts/{post}')->middleware);
        self::assertSame('posts.update', self::route($routes, 'PATCH', 'api/posts/{post}')->name);
        self::assertSame(['api'], self::route($routes, 'GET', 'api/status')->middleware, 'withoutMiddleware removes only what it names');
    }

    public function testCustomApiPrefixAndThenCallback(): void
    {
        $routes = $this->parse($this->project([
            'bootstrap/app.php' => <<<'PHP'
                <?php
                return Application::configure(basePath: dirname(__DIR__))
                    ->withRouting(
                        api: __DIR__.'/../routes/api.php',
                        apiPrefix: 'api/v1',
                        then: function () {
                            Route::middleware('api')->prefix('webhooks')->name('webhooks.')->group(base_path('routes/webhooks.php'));
                        },
                    )->create();
                PHP,
            'routes/api.php' => "<?php\nRoute::get('/ping', fn () => 'pong');\n",
            'routes/webhooks.php' => "<?php\nRoute::post('/stripe', [App\\Http\\Controllers\\StripeWebhookController::class, 'handle'])->name('stripe');\n",
        ]));

        self::assertSame('api/v1/ping', self::route($routes, 'GET', 'api/v1/ping')->uri);
        $webhook = self::route($routes, 'POST', 'webhooks/stripe');
        self::assertSame('webhooks.stripe', $webhook->name);
        self::assertTrue($webhook->controllerMissing, 'App\\ controller without a file under app/');
    }

    public function testRegistrarVariantsAndModifiers(): void
    {
        $routes = $this->parse($this->project([
            'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__.'/../routes/web.php')->create();\n",
            'routes/web.php' => <<<'PHP'
                <?php
                use App\Http\Controllers\PhotoController;
                use App\Http\Controllers\ThumbnailController;
                use Illuminate\Support\Facades\Route;

                Route::match(['get', 'post'], '/contact', [PhotoController::class, 'contact'])->middleware('throttle:5,1');
                Route::any('/anything', fn () => 'x');
                Route::permanentRedirect('/old', '/new');
                Route::fallback(fn () => abort(404));
                Route::resources(['photos' => PhotoController::class, 'albums' => PhotoController::class]);
                Route::apiResource('photos.comments', PhotoController::class)->except(['destroy'])->names('photo-comments');
                Route::resource('tags', PhotoController::class)->names(['index' => 'tags.list'])->only(['index']);
                Route::singleton('profile', PhotoController::class)->creatable();
                Route::get('/thumb', ThumbnailController::class)->can('view', 'photo')->withoutMiddleware('web');
                Route::get('/legacy', ['uses' => 'App\Http\Controllers\PhotoController@legacy', 'as' => 'legacy', 'middleware' => 'auth']);
                $router = app('router');
                $router->get('/via-variable', fn () => 'ok');
                if (app()->isLocal()) {
                    Route::get('/local-only', fn () => phpinfo());
                }
                Route::prefix(config('app.prefix'))->group(fn () => Route::get('/dyn', fn () => 'x'));
                PHP,
        ]));

        self::assertSame(['GET', 'POST'], self::route($routes, 'POST', 'contact')->methods);
        self::assertSame(['web', 'throttle:5,1'], self::route($routes, 'GET', 'contact')->middleware);
        self::assertSame(['ANY'], self::route($routes, 'ANY', 'anything')->methods);
        self::assertSame('Redirect to /new', self::route($routes, 'ANY', 'old')->action);
        self::assertSame('{fallbackPlaceholder}', self::route($routes, 'GET', '{fallbackPlaceholder}')->uri);
        self::assertSame('albums.edit', self::route($routes, 'GET', 'albums/{album}/edit')->name);
        self::assertSame('photo-comments.show', self::route($routes, 'GET', 'photos/{photo}/comments/{comment}')->name);
        self::assertSame([], array_filter($routes->routes, static fn ($r) => $r->name === 'photo-comments.destroy'));
        self::assertSame('tags.list', self::route($routes, 'GET', 'tags')->name);
        self::assertSame('profile.store', self::route($routes, 'POST', 'profile')->name);
        self::assertSame('profile.destroy', self::route($routes, 'DELETE', 'profile')->name);

        $thumb = self::route($routes, 'GET', 'thumb');
        self::assertSame('App\\Http\\Controllers\\ThumbnailController', $thumb->action);
        self::assertSame(['can:view,photo'], $thumb->middleware);

        $legacy = self::route($routes, 'GET', 'legacy');
        self::assertSame('legacy', $legacy->name);
        self::assertSame(['web', 'auth'], $legacy->middleware);

        self::assertSame('Closure', self::route($routes, 'GET', 'via-variable')->action);
        self::assertSame(['phpinfo'], self::route($routes, 'GET', 'local-only')->closureCalls, 'Routes inside if-blocks are found and closure calls recorded.');
        self::assertSame('{dynamic}/dyn', self::route($routes, 'GET', '{dynamic}/dyn')->uri);
    }

    public function testControllerAttributesAndParentMiddleware(): void
    {
        $routes = $this->parse($this->project([
            'routes/web.php' => "<?php\nRoute::get('/a', [App\\Http\\Controllers\\AttrController::class, 'store']);\nRoute::get('/b', [App\\Http\\Controllers\\AttrController::class, 'index']);\nRoute::get('/c', [App\\Http\\Controllers\\Admin\\UsersController::class, 'index']);\n",
            'app/Http/Controllers/AttrController.php' => <<<'PHP'
                <?php
                namespace App\Http\Controllers;
                use Illuminate\Routing\Attributes\Controllers\Authorize;
                use Illuminate\Routing\Attributes\Controllers\Middleware;
                #[Middleware('auth', except: ['index'])]
                class AttrController
                {
                    public function index() {}
                    #[Authorize('create', 'post')]
                    public function store() {}
                }
                PHP,
            'app/Http/Controllers/Admin/AdminController.php' => "<?php\nnamespace App\\Http\\Controllers\\Admin;\nabstract class AdminController\n{\n    public function __construct()\n    {\n        \$this->middleware(['auth', 'role:admin']);\n    }\n}\n",
            'app/Http/Controllers/Admin/UsersController.php' => "<?php\nnamespace App\\Http\\Controllers\\Admin;\nclass UsersController extends AdminController\n{\n    public function index() {}\n}\n",
        ]));

        self::assertSame(['web', 'auth', 'can:create'], self::route($routes, 'GET', 'a')->middleware);
        self::assertSame(['web'], self::route($routes, 'GET', 'b')->middleware);
        self::assertSame(['web', 'auth', 'role:admin'], self::route($routes, 'GET', 'c')->middleware, 'Middleware declared in a project parent controller applies.');
        self::assertStringContainsString('Laravel\'s default web and api groups were assumed', implode(' ', $routes->notes));
    }

    public function testCyclesMissingFilesAndDynamicIncludesAreNoted(): void
    {
        $routes = $this->parse($this->project([
            'routes/web.php' => "<?php\nRoute::get('/one', fn () => 1);\nrequire __DIR__.'/extra.php';\nrequire __DIR__.'/missing.php';\nrequire \$path;\n",
            'routes/extra.php' => "<?php\nRoute::get('/two', fn () => 2);\nrequire __DIR__.'/web.php';\n",
            'routes/api.php' => "<?php\nthis is not php\n",
        ]));

        self::assertCount(2, $routes->routes);
        $notes = implode("\n", $routes->notes);
        self::assertStringContainsString('routes/missing.php is referenced but does not exist', $notes);
        self::assertStringContainsString('computed at runtime', $notes);
        self::assertStringContainsString('routes/api.php could not be parsed', $notes);
    }

    public function testIncludesCannotEscapeTheProject(): void
    {
        $routes = $this->parse($this->project([
            'routes/web.php' => "<?php\nrequire __DIR__.'/../../../../etc/passwd';\nrequire '/etc/passwd';\nRoute::get('/ok', fn () => 1);\n",
        ]));

        self::assertCount(1, $routes->routes);
        self::assertStringNotContainsString('passwd', implode(' ', $routes->files));
    }
}
