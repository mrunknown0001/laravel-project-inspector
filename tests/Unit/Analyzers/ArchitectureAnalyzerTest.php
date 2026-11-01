<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\ArchitectureAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ArchitectureAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private function analyze(string $root): AnalyzerResult
    {
        return (new ArchitectureAnalyzer())->analyze(Fixtures::context($root));
    }

    public function testClassifiesComponentsOfAFixture(): void
    {
        $result = $this->analyze(Fixtures::project('laravel-11'));

        self::assertSame(['Controllers' => 4, 'Livewire components' => 1, 'Models' => 1], $result->data['components']);
        self::assertStringContainsString('Laravel 11 application using the Laravel 11+ application structure', $result->data['overview']);
        self::assertStringContainsString('serves both web pages and an API', $result->data['overview']);
        self::assertSame(['Livewire (1 component)'], $result->data['frontend']);
    }

    public function testDetectsPatternsIntegrationsAndLargeClasses(): void
    {
        $big = "<?php\nnamespace App\\Services;\nclass ReportService\n{\n";
        for ($i = 0; $i < 30; $i++) {
            $big .= "    public function method{$i}() { return Http::get('https://api.example.test/{$i}'); }\n";
        }
        $big .= "}\n";

        $root = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => json_encode([
                'require' => ['laravel/framework' => '^11.0', 'stripe/stripe-php' => '^15.0', 'laravel/sanctum' => '^4.0', 'inertiajs/inertia-laravel' => '^2.0'],
                'autoload' => ['psr-4' => ['App\\' => 'app/', 'Domain\\' => 'src/Domain/']],
            ], JSON_THROW_ON_ERROR),
            'app/Services/ReportService.php' => $big,
            'app/Repositories/UserRepository.php' => "<?php\nnamespace App\\Repositories;\nclass UserRepository implements \\App\\Contracts\\UserRepositoryInterface {}\n",
            'app/Contracts/UserRepositoryInterface.php' => "<?php\nnamespace App\\Contracts;\ninterface UserRepositoryInterface {}\n",
            'app/Jobs/SendInvoice.php' => "<?php\nnamespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass SendInvoice implements ShouldQueue {}\n",
            'app/Events/OrderPlaced.php' => "<?php\nnamespace App\\Events;\nclass OrderPlaced {}\n",
            'app/Listeners/NotifyWarehouse.php' => "<?php\nnamespace App\\Listeners;\nclass NotifyWarehouse {}\n",
            'app/Enums/Status.php' => "<?php\nnamespace App\\Enums;\nenum Status: string { case Open = 'open'; }\n",
            'app/Http/Requests/StoreOrderRequest.php' => "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass StoreOrderRequest extends FormRequest {}\n",
            'app/Http/Controllers/OrderController.php' => "<?php\nnamespace App\\Http\\Controllers;\nclass OrderController { public function index() { return Inertia::render('Orders/Index'); } }\n",
            'src/Domain/Billing/Invoice.php' => "<?php\nnamespace Domain\\Billing;\nclass Invoice extends \\Illuminate\\Database\\Eloquent\\Model {}\n",
            'resources/js/Pages/Orders/Index.vue' => '<template></template>',
            'config/services.php' => "<?php return ['stripe' => ['key' => env('STRIPE_KEY')], 'postmark' => []];",
        ]);
        $result = $this->analyze($root);
        $components = $result->data['components'];

        self::assertSame(1, $components['Services']);
        self::assertSame(1, $components['Repositories']);
        self::assertSame(1, $components['Jobs']);
        self::assertSame(1, $components['Events']);
        self::assertSame(1, $components['Listeners']);
        self::assertSame(1, $components['Enums']);
        self::assertSame(1, $components['Form requests']);
        self::assertSame(1, $components['Models'], 'Classes under other PSR-4 roots are classified by inheritance.');
        self::assertSame(1, $components['Interfaces (contracts)']);

        self::assertContains('Service layer (1 service class)', $result->data['patterns']);
        self::assertContains('Event-driven (1 event, 1 listener)', $result->data['patterns']);
        self::assertContains('Stripe', $result->data['integrations']);
        self::assertSame(['Sanctum'], $result->data['auth_packages']);
        self::assertSame(['stripe', 'postmark'], $result->data['services']);
        self::assertSame(30, $result->data['outbound_http']['http_facade']);
        self::assertSame(['Inertia (1 page, 1 Inertia::render call)'], $result->data['frontend']);
        self::assertSame('arch.large-classes', $result->findings[0]->id);
        self::assertStringContainsString('App\\Services\\ReportService', $result->findings[0]->evidence);
    }

    public function testMissingConventionalDirectoriesAreNotFindings(): void
    {
        $result = $this->analyze($this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^11.0"}}',
            'app/Thing.php' => "<?php\nnamespace App;\nclass Thing {}\n",
        ]));

        self::assertSame([], $result->findings);
        self::assertStringContainsString('no service, action or repository layer detected', $result->data['overview']);
    }
}
