<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Routing;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Core\ProjectDetector;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Routing\RouteLoader;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RouteLoaderTest extends TestCase
{
    private function context(FakeProcessRunner $runner, bool $useArtisan): InspectionContext
    {
        $files = Fixtures::reader(Fixtures::project('laravel-11'));

        return new InspectionContext((new ProjectDetector())->detect($files), $files, $runner, new SecretRedactor(), new InspectionOptions(useArtisan: $useArtisan));
    }

    public function testStaticByDefaultWithoutRunningAnything(): void
    {
        $runner = new FakeProcessRunner();
        $routes = (new RouteLoader())->load($this->context($runner, false));

        self::assertSame(RouteDefinition::SOURCE_STATIC, $routes->source);
        self::assertSame([], $runner->commands);
    }

    public function testArtisanIsUsedWhenAllowedAndMarkedAsSideEffect(): void
    {
        $json = json_encode([
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'telescope/{view?}', 'name' => 'telescope', 'action' => 'Laravel\\Telescope\\Http\\Controllers\\HomeController@index', 'middleware' => ['web', 'Laravel\\Telescope\\Http\\Middleware\\Authorize']],
            ['domain' => null, 'method' => 'POST', 'uri' => 'api/posts', 'name' => 'posts.store', 'action' => 'App\\Http\\Controllers\\Api\\PostController@store', 'middleware' => ['api', 'auth:sanctum']],
        ], JSON_THROW_ON_ERROR);
        $runner = (new FakeProcessRunner())->respond('php artisan', ProcessResult::completed(0, "Deprecated: something\n" . $json));

        $routes = (new RouteLoader())->load($this->context($runner, true));

        self::assertSame(RouteDefinition::SOURCE_ARTISAN, $routes->source);
        self::assertCount(2, $routes->routes);
        self::assertSame('authenticated', $routes->routes[0]->access());

        $command = $runner->commands[0];
        self::assertSame(['artisan', 'route:list', '--json', '--no-ansi', '--no-interaction'], $command->arguments);
        self::assertTrue($command->sideEffects, 'Booting the app must require confirmation.');
        self::assertStringContainsString('boots the Laravel application', (string) $command->warning);
    }

    public function testFallsBackToStaticWhenArtisanIsRefusedOrFails(): void
    {
        $refused = (new FakeProcessRunner())->respond('php artisan', ProcessResult::refused('the command may have side effects and was not confirmed'));
        $routes = (new RouteLoader())->load($this->context($refused, true));

        self::assertSame(RouteDefinition::SOURCE_STATIC, $routes->source);
        self::assertStringContainsString('artisan route:list was not used (the command was not permitted', $routes->notes[0]);
        self::assertGreaterThan(5, $routes->count());

        $broken = (new FakeProcessRunner())->respond('php artisan', ProcessResult::completed(0, 'Illuminate\\Database\\QueryException'));
        self::assertStringContainsString('not valid JSON', (new RouteLoader())->load($this->context($broken, true))->notes[0]);
    }
}
