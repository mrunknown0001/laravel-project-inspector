<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Architecture\ClassIndex;
use LaraInspect\Architecture\ClassInfo;
use LaraInspect\Architecture\ComponentClassifier;
use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\ProjectInfo;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Routing\RouteDefinition;
use LaraInspect\Support\Evidence;

/**
 * An overview of how the application is built, from the classes it declares,
 * the packages it installs and the routes it registers.
 *
 * Missing conventional directories are never treated as a problem: many
 * healthy applications use other structures (modules, domains, actions).
 */
final class ArchitectureAnalyzer implements AnalyzerInterface
{
    private const LARGE_CLASS_LINES = 500;
    private const LARGE_CLASS_PUBLIC_METHODS = 25;

    /** composer package => label */
    private const INTEGRATIONS = [
        'stripe/stripe-php' => 'Stripe', 'laravel/cashier' => 'Laravel Cashier', 'laravel/cashier-paddle' => 'Cashier (Paddle)',
        'aws/aws-sdk-php' => 'AWS SDK', 'league/flysystem-aws-s3-v3' => 'Amazon S3 storage', 'twilio/sdk' => 'Twilio',
        'google/apiclient' => 'Google APIs', 'mailgun/mailgun-php' => 'Mailgun', 'symfony/mailgun-mailer' => 'Mailgun',
        'symfony/postmark-mailer' => 'Postmark', 'resend/resend-laravel' => 'Resend', 'pusher/pusher-php-server' => 'Pusher',
        'laravel/reverb' => 'Laravel Reverb', 'laravel/socialite' => 'Socialite (OAuth login)', 'openai-php/client' => 'OpenAI',
        'openai-php/laravel' => 'OpenAI', 'prism-php/prism' => 'Prism (LLM providers)', 'laravel/ai' => 'Laravel AI SDK',
        'sentry/sentry-laravel' => 'Sentry', 'bugsnag/bugsnag-laravel' => 'Bugsnag', 'algolia/algoliasearch-client-php' => 'Algolia',
        'meilisearch/meilisearch-php' => 'Meilisearch', 'kreait/laravel-firebase' => 'Firebase', 'laravel/vonage-notification-channel' => 'Vonage',
        'laravel/slack-notification-channel' => 'Slack', 'spatie/laravel-medialibrary' => 'Media library',
    ];

    private const AUTH_PACKAGES = [
        'laravel/sanctum' => 'Sanctum', 'laravel/passport' => 'Passport', 'laravel/fortify' => 'Fortify',
        'laravel/breeze' => 'Breeze', 'laravel/jetstream' => 'Jetstream', 'laravel/socialite' => 'Socialite',
        'tymon/jwt-auth' => 'JWT Auth', 'php-open-source-saver/jwt-auth' => 'JWT Auth', 'spatie/laravel-permission' => 'Spatie Permission',
    ];

    public function name(): string
    {
        return 'architecture';
    }

    public function title(): string
    {
        return 'Architecture';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $roots = $this->codeRoots($context);
        $files = array_values(array_filter(
            $context->sourceFiles(['php'], $roots),
            static fn (string $f): bool => !str_ends_with($f, '.blade.php'),
        ));
        $index = ClassIndex::build($context->files, $files);
        $classifier = new ComponentClassifier();

        /** @var array<string, list<ClassInfo>> $components */
        $components = [];
        foreach ($index->classes() as $class) {
            $components[$classifier->classify($class, $index)][] = $class;
        }
        uksort($components, static fn (string $a, string $b): int => [$a === ComponentClassifier::OTHER, $a] <=> [$b === ComponentClassifier::OTHER, $b]);
        $count = static fn (string $component): int => count($components[$component] ?? []);

        $inventory = [];
        foreach ($components as $component => $classes) {
            $examples = array_slice(array_map(static fn (ClassInfo $c): string => $c->shortName(), $classes), 0, 4);
            $inventory[] = [$component, (string) count($classes), implode(', ', $examples) . (count($classes) > 4 ? ', ...' : ''), $this->mainDirectory($classes)];
        }

        $composer = $context->composer();
        $has = static fn (string $package): bool => $composer->lockedPackage($package) !== null || isset($composer->require()[$package]) || isset($composer->requireDev()[$package]);

        $integrations = [];
        foreach (self::INTEGRATIONS as $package => $label) {
            if ($has($package)) {
                $integrations[$label] = $package;
            }
        }
        $authStack = [];
        foreach (self::AUTH_PACKAGES as $package => $label) {
            if ($has($package)) {
                $authStack[] = $label;
            }
        }
        $services = $context->config()->keys('services');

        $views = count($context->files->findFiles('resources/views', ['php']));
        $inertiaPages = $this->inertiaPageCount($context);
        $frontend = [];
        if ($count('Livewire components') > 0 || $has('livewire/livewire')) {
            $frontend[] = sprintf('Livewire (%s)', Evidence::plural($count('Livewire components'), 'component'));
        }
        if ($has('inertiajs/inertia-laravel')) {
            $frontend[] = sprintf('Inertia (%s, %s)', Evidence::plural($inertiaPages, 'page'), Evidence::plural($index->usage('inertia'), 'Inertia::render call'));
        }
        if ($has('filament/filament')) {
            $frontend[] = sprintf('Filament admin (%s)', Evidence::plural($count('Filament resources'), 'resource'));
        }
        if ($views > 0) {
            $frontend[] = sprintf('Blade (%s)', Evidence::plural($views, 'view'));
        }

        $routes = $context->routes();
        $apiRoutes = count(array_filter($routes->routes, static fn (RouteDefinition $r): bool => $r->isApi()));
        $modular = array_values(array_filter(['Modules', 'modules', 'Domain', 'Domains', 'src/Domain', 'app/Domain', 'app/Modules'], fn (string $d): bool => $context->files->isDirectory($d)));

        $patterns = [];
        if ($count('Services') > 0) {
            $patterns[] = sprintf('Service layer (%s)', Evidence::plural($count('Services'), 'service class', 'service classes'));
        }
        if ($count('Repositories') > 0) {
            $patterns[] = sprintf('Repository pattern (%s)', Evidence::plural($count('Repositories'), 'repository', 'repositories'));
        }
        if ($count('Actions') > 0) {
            $patterns[] = sprintf('Action classes (%d)', $count('Actions'));
        }
        if ($count('Data objects') > 0) {
            $patterns[] = sprintf('Data transfer objects (%d)', $count('Data objects'));
        }
        if ($count('Events') + $count('Listeners') > 0) {
            $patterns[] = sprintf('Event-driven (%s, %s)', Evidence::plural($count('Events'), 'event'), Evidence::plural($count('Listeners'), 'listener'));
        }
        if ($count('Jobs') > 0) {
            $patterns[] = sprintf('Queued jobs (%d)', $count('Jobs'));
        }
        if ($modular !== [] || $has('nwidart/laravel-modules')) {
            $patterns[] = 'Modular / domain structure (' . ($modular === [] ? 'nwidart/laravel-modules' : implode(', ', $modular)) . ')';
        }
        if ($count('Interfaces (contracts)') > 0) {
            $patterns[] = sprintf('Interfaces / contracts (%d)', $count('Interfaces (contracts)'));
        }

        $overview = $this->overview($context->project, $components, $patterns, $frontend, $integrations, count($routes->routes), $apiRoutes);

        $facts = [
            new Fact('Overview', $overview, Source::Inferred, 'derived from detected classes, packages and routes'),
            new Fact('Code roots scanned', implode(', ', $roots), Source::Detected, 'composer.json autoload'),
            new Fact('Classes, interfaces, traits and enums', (string) count($index->classes()), Source::Detected, null),
            new Fact('Patterns detected', $patterns === [] ? 'Conventional Laravel MVC' : implode('; ', $patterns), Source::Inferred, null),
            new Fact('Frontend', $frontend === [] ? 'No server-rendered views or SPA bridge detected' : implode('; ', $frontend), Source::Detected, null),
            new Fact('Authentication packages', $authStack === [] ? 'None (framework defaults)' : implode(', ', $authStack), Source::Detected, 'composer.json'),
            new Fact('API surface', sprintf('%d of %d routes are API routes', $apiRoutes, count($routes->routes)), $routes->source === RouteDefinition::SOURCE_ARTISAN ? Source::Detected : Source::Inferred, 'route analysis'),
            new Fact('Outbound HTTP', sprintf('%d Http facade calls, %d Guzzle clients', $index->usage('http'), $index->usage('guzzle')), Source::Detected, 'static scan'),
            new Fact('Third-party integrations', $integrations === [] ? 'None detected' : implode(', ', array_keys($integrations)), Source::Detected, 'composer.json'),
            new Fact('Configured services', $services === [] ? 'None' : implode(', ', $services), Source::Detected, 'config/services.php'),
        ];

        $tables = [new Table('Component inventory', ['Component', 'Count', 'Examples', 'Main location'], $inventory)];

        $notes = ['This overview is inferred from file structure, class inheritance and installed packages. A missing conventional directory does not mean anything is wrong.'];
        if ($index->unparsed() !== []) {
            $notes[] = sprintf('%s could not be parsed: %s', Evidence::plural(count($index->unparsed()), 'file'), implode(', ', array_slice($index->unparsed(), 0, 5)));
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: [...$this->largeClassFindings($index), ...$this->routeClosureFindings($routes->routes)],
            notes: $notes,
            data: [
                'overview' => $overview,
                'components' => array_map('count', $components),
                'patterns' => $patterns,
                'frontend' => $frontend,
                'auth_packages' => $authStack,
                'integrations' => array_keys($integrations),
                'services' => $services,
                'outbound_http' => ['http_facade' => $index->usage('http'), 'guzzle' => $index->usage('guzzle')],
            ],
        );
    }

    /**
     * Inertia pages live in resources/js/Pages (or pages). On case-insensitive file systems
     * (Windows, macOS) both names are the same directory, so each one is counted once.
     */
    private function inertiaPageCount(InspectionContext $context): int
    {
        $caseInsensitive = in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true);
        $directories = [];
        foreach (['resources/js/Pages', 'resources/js/pages'] as $directory) {
            if ($context->files->isDirectory($directory)) {
                $real = (string) realpath($context->root() . DIRECTORY_SEPARATOR . $directory);
                $directories[$caseInsensitive ? mb_strtolower($real) : $real] = $directory;
            }
        }

        return array_sum(array_map(static fn (string $d): int => count($context->files->findFiles($d)), $directories));
    }

    /**
     * @return list<string>
     */
    private function codeRoots(InspectionContext $context): array
    {
        $roots = [];
        foreach ($context->composer()->psr4() as $prefix => $dirs) {
            if (str_starts_with($prefix, 'Database\\')) {
                continue;
            }
            array_push($roots, ...$dirs);
        }
        $roots = array_values(array_unique(array_filter($roots === [] ? ['app'] : $roots, fn (string $d): bool => $context->files->isDirectory($d))));

        return $roots === [] && $context->files->isDirectory('app') ? ['app'] : $roots;
    }

    /**
     * @param list<ClassInfo> $classes
     */
    private function mainDirectory(array $classes): string
    {
        $directories = array_count_values(array_map(static fn (ClassInfo $c): string => dirname($c->file), $classes));
        arsort($directories);

        return (string) array_key_first($directories);
    }

    /**
     * @param array<string, list<ClassInfo>> $components
     * @param list<string>                   $patterns
     * @param list<string>                   $frontend
     * @param array<string, string>          $integrations
     */
    private function overview(ProjectInfo $project, array $components, array $patterns, array $frontend, array $integrations, int $routes, int $apiRoutes): string
    {
        $count = static fn (string $c): int => count($components[$c] ?? []);
        $sentences = [];

        $version = $project->laravelMajor !== null ? 'Laravel ' . $project->laravelMajor : 'Laravel';
        $structure = match ($project->layout) {
            ProjectInfo::LAYOUT_MODERN => ' using the Laravel 11+ application structure',
            ProjectInfo::LAYOUT_LEGACY => ' using the classic kernel-based structure',
            default => '',
        };
        $sentences[] = sprintf('%s application%s with %s, %s and %s.', $version, $structure,
            Evidence::plural($count('Controllers'), 'controller'), Evidence::plural($count('Models'), 'model'), Evidence::plural($routes, 'route'));

        if ($routes > 0) {
            $share = $apiRoutes / $routes;
            $sentences[] = match (true) {
                $share >= 0.7 => 'It is primarily an API backend.',
                $share >= 0.2 => 'It serves both web pages and an API.',
                default => 'It is primarily a server-rendered web application.',
            };
        }
        if ($frontend !== []) {
            $sentences[] = 'User interface: ' . implode(', ', $frontend) . '.';
        }
        if ($patterns !== []) {
            $sentences[] = 'Detected structure: ' . implode(', ', $patterns) . '.';
        } else {
            $sentences[] = 'Business logic appears to live in controllers and models (no service, action or repository layer detected).';
        }
        if ($integrations !== []) {
            $sentences[] = 'Integrates with ' . implode(', ', array_keys($integrations)) . '.';
        }

        return implode(' ', $sentences);
    }

    /**
     * @return list<Finding>
     */
    private function largeClassFindings(ClassIndex $index): array
    {
        $large = [];
        foreach ($index->classes() as $class) {
            if ($class->lines > self::LARGE_CLASS_LINES || $class->publicMethods > self::LARGE_CLASS_PUBLIC_METHODS) {
                $large[] = sprintf('%s (%d lines, %d public methods) %s', $class->name, $class->lines, $class->publicMethods, $class->file);
            }
        }
        if ($large === []) {
            return [];
        }

        return [new Finding(
            'arch.large-classes',
            sprintf('%s unusually large', Evidence::plural(count($large), 'class is', 'classes are')),
            Severity::Info,
            Confidence::Potential,
            'source code',
            sprintf('Classes over %d lines or with more than %d public methods often combine several responsibilities and are harder to test and change.', self::LARGE_CLASS_LINES, self::LARGE_CLASS_PUBLIC_METHODS),
            Evidence::list($large),
            'Consider extracting cohesive parts into services, actions or form requests when these classes next need changes.',
        )];
    }

    /**
     * @param list<RouteDefinition> $routes
     * @return list<Finding>
     */
    private function routeClosureFindings(array $routes): array
    {
        $closures = array_values(array_filter($routes, static fn (RouteDefinition $r): bool => $r->isClosure));
        if (count($closures) < 10 || count($closures) < count($routes) / 3) {
            return [];
        }

        return [new Finding(
            'arch.closure-routes',
            sprintf('%d of %d routes use closures', count($closures), count($routes)),
            Severity::Info,
            Confidence::Confirmed,
            'routes/',
            'Many closure routes usually means request handling logic lives in route files. That is harder to test and reuse, and cannot use controller middleware or dependency injection conventions.',
            Evidence::list(array_map(static fn (RouteDefinition $r): string => sprintf('%s %s (%s:%d)', $r->methodLabel(), $r->uri, $r->file, $r->line), $closures)),
            'Move non-trivial closures into (invokable) controllers.',
        )];
    }
}
