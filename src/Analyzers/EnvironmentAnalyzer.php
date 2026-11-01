<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\ProjectDetector;
use LaraInspect\Core\ProjectInfo;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Parsing\ConfigValue;
use LaraInspect\Parsing\SettingResolver;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Support\Arr;
use LaraInspect\Support\LaravelSupportPolicy;
use LaraInspect\Support\Versions;

/**
 * Versions, runtime tooling, frontend stack and infrastructure drivers.
 */
final class EnvironmentAnalyzer implements AnalyzerInterface
{
    /** label => [executable, arguments] */
    private const TOOLS = [
        'Composer' => ['composer', ['--version', '--no-ansi']],
        'Node.js' => ['node', ['--version']],
        'npm' => ['npm', ['--version']],
        'pnpm' => ['pnpm', ['--version']],
        'Yarn' => ['yarn', ['--version']],
        'Bun' => ['bun', ['--version']],
    ];

    private const DRIVER_LABELS = [
        'database' => 'Database connection',
        'cache' => 'Cache store',
        'queue' => 'Queue connection',
        'session' => 'Session driver',
        'log' => 'Log channel',
        'filesystem' => 'Filesystem disk',
        'mail' => 'Mailer',
        'broadcast' => 'Broadcast connection',
    ];

    /** package => label, checked in package.json dependencies */
    private const FRONTEND_PACKAGES = [
        'vue' => 'Vue',
        'react' => 'React',
        'svelte' => 'Svelte',
        '@inertiajs/vue3' => 'Inertia (Vue)',
        '@inertiajs/react' => 'Inertia (React)',
        '@inertiajs/svelte' => 'Inertia (Svelte)',
        '@inertiajs/inertia' => 'Inertia (legacy adapter)',
        'alpinejs' => 'Alpine.js',
        'tailwindcss' => 'Tailwind CSS',
        'bootstrap' => 'Bootstrap',
        'jquery' => 'jQuery',
        'typescript' => 'TypeScript',
        'laravel-echo' => 'Laravel Echo',
    ];

    /** composer package => label */
    private const FRONTEND_COMPOSER_PACKAGES = [
        'livewire/livewire' => 'Livewire',
        'inertiajs/inertia-laravel' => 'Inertia (server adapter)',
        'filament/filament' => 'Filament',
        'laravel/jetstream' => 'Jetstream',
        'laravel/breeze' => 'Breeze',
    ];

    public function name(): string
    {
        return 'environment';
    }

    public function title(): string
    {
        return 'Environment';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $project = $context->project;
        $composer = $context->composer();
        $settings = new SettingResolver($context);
        $facts = [];
        $findings = [];
        $notes = [];

        // Framework
        $facts[] = $project->laravelVersion !== null
            ? new Fact('Laravel version', $project->laravelVersion, $project->versionSource, $project->versionOrigin)
            : Fact::unknown('Laravel version', 'Not determined (no composer.lock, vendor or constraint)');
        $facts[] = new Fact('Project layout', $this->describeLayout($project->layout), Source::Inferred, 'bootstrap/app.php, app/Http/Kernel.php');

        if ($project->laravelMajor !== null) {
            [$supportFact, $supportFinding] = $this->supportStatus($project->laravelMajor, $context->options->now, $project->versionOrigin ?? 'composer.json');
            $facts[] = $supportFact;
            if ($supportFinding !== null) {
                $findings[] = $supportFinding;
            }
        }

        // PHP
        $phpConstraint = $composer->phpConstraint();
        $facts[] = $phpConstraint !== null
            ? new Fact('PHP requirement', $phpConstraint, Source::Detected, 'composer.json')
            : Fact::unknown('PHP requirement', 'Not declared in composer.json');
        $platformPhp = $composer->platformPhpOverride();
        if ($platformPhp !== null) {
            $facts[] = new Fact('PHP platform override', $platformPhp, Source::Detected, 'composer.json config.platform.php');
        }
        $facts[] = new Fact('PHP running the inspector', PHP_VERSION, Source::Detected, PHP_BINARY);

        if ($composer->hasManifest() && $phpConstraint === null) {
            $findings[] = new Finding(
                'env.php-requirement-missing',
                'composer.json does not declare a PHP version requirement',
                Severity::Low,
                Confidence::Confirmed,
                'composer.json',
                'Without a "php" entry in "require", Composer cannot stop installs on unsupported PHP versions, and hosting platforms cannot pick the right runtime.',
                'No "php" key under "require" in composer.json.',
                'Add the supported range, e.g. "php": "^8.2", matching the Laravel version in use.',
            );
        }
        if ($phpConstraint !== null && Versions::satisfies(PHP_VERSION, $phpConstraint) === false) {
            $findings[] = new Finding(
                'env.runtime-php-mismatch',
                'Local PHP does not satisfy the project PHP requirement',
                Severity::Info,
                Confidence::Potential,
                'composer.json',
                'The PHP binary running this inspection is outside the range the project declares. This only matters if this machine is also used to run or develop the project; production may use a different PHP.',
                sprintf('Runtime PHP %s; composer.json requires "%s".', PHP_VERSION, $phpConstraint),
                'Use a PHP version within the declared range when running the project on this machine.',
            );
        }

        // Tooling (run outside the project so local config files there are never picked up)
        $tools = [];
        foreach (self::TOOLS as $label => [$executable, $arguments]) {
            $version = $this->toolVersion($context, $executable, $arguments, $label);
            $tools[$label] = $version;
            $facts[] = $version !== null
                ? new Fact($label . ' version', $version, Source::Detected, $executable . ' ' . implode(' ', $arguments))
                : new Fact($label . ' version', 'Not installed', Source::Detected, 'PATH lookup');
        }

        // Application environment
        $env = $context->env();
        if (!$env->exists()) {
            $notes[] = 'No .env or .env.example file was found; environment values come from config files and framework defaults.';
        } elseif ($env->isExample()) {
            $notes[] = 'No .env file was found; values were taken from .env.example and are marked as inferred.';
        }
        $appEnv = $settings->resolve('environment');
        $facts[] = $this->settingFact('Application environment', $appEnv);
        $appDebug = $settings->resolve('debug');
        $facts[] = $this->settingFact('Debug mode', $appDebug);

        // Drivers
        $drivers = [];
        $driverRows = [];
        foreach (self::DRIVER_LABELS as $setting => $label) {
            $value = $settings->resolve($setting);
            $drivers[$setting] = $value->asString();
            $driverRows[] = [$label, $value->display(), $value->source->value, $value->origin];
        }

        $databaseTechnology = null;
        $connection = $drivers['database'] ?? null;
        if ($connection !== null) {
            $driver = $settings->databaseDriver($connection);
            $databaseTechnology = $driver !== null ? (SettingResolver::DATABASE_DRIVERS[$driver] ?? $driver) : null;
        }
        $facts[] = $databaseTechnology !== null
            ? new Fact('Database technology', $databaseTechnology, $settings->resolve('database')->source, 'config/database.php')
            : Fact::unknown('Database technology');

        // Frontend
        $frontend = $this->detectFrontend($context);
        $facts[] = new Fact('Frontend build tool', $frontend['build_tool'] ?? 'None detected', Source::Detected, $frontend['build_tool_origin'] ?? null);
        $facts[] = new Fact('Frontend package manager', $frontend['package_manager'] ?? 'None detected', Source::Detected, $frontend['package_manager_origin'] ?? null);
        $facts[] = new Fact(
            'Frontend technologies',
            $frontend['technologies'] === [] ? 'Blade only' : implode(', ', $frontend['technologies']),
            Source::Detected,
            'package.json, composer.json',
        );

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: [new Table('Infrastructure drivers', ['Setting', 'Value', 'Source', 'Origin'], $driverRows)],
            findings: $findings,
            notes: $notes,
            data: [
                'laravel' => ['version' => $project->laravelVersion, 'major' => $project->laravelMajor, 'layout' => $project->layout],
                'php' => ['requirement' => $phpConstraint, 'platform_override' => $platformPhp, 'runtime' => PHP_VERSION],
                'tools' => $tools,
                'app' => ['environment' => $appEnv->asString(), 'debug' => $appDebug->asString()],
                'drivers' => $drivers,
                'database_technology' => $databaseTechnology,
                'frontend' => $frontend,
            ],
        );
    }

    private function describeLayout(string $layout): string
    {
        return match ($layout) {
            ProjectInfo::LAYOUT_MODERN => 'Laravel 11+ style (bootstrap/app.php configures routing and middleware)',
            ProjectInfo::LAYOUT_LEGACY => 'Classic (HTTP/Console kernels)',
            default => 'Unrecognised',
        };
    }

    /**
     * @return array{0: Fact, 1: Finding|null}
     */
    private function supportStatus(int $major, \DateTimeImmutable $now, string $origin): array
    {
        $status = LaravelSupportPolicy::status($major, $now);
        $policy = LaravelSupportPolicy::for($major);

        if ($policy === null) {
            $fact = new Fact('Laravel support status', $status === LaravelSupportPolicy::STATUS_END_OF_LIFE
                ? 'End of life (released before the oldest tracked version)'
                : 'Not in the inspector\'s support table', Source::Inferred, LaravelSupportPolicy::SOURCE_URL);
        } else {
            $fact = new Fact('Laravel support status', match ($status) {
                LaravelSupportPolicy::STATUS_END_OF_LIFE => sprintf('End of life (security fixes ended %s)', $policy['security_fixes_until']),
                LaravelSupportPolicy::STATUS_SECURITY_ONLY => sprintf('Security fixes only, until %s', $policy['security_fixes_until']),
                default => sprintf('Active (bug fixes until %s, security fixes until %s)', $policy['bug_fixes_until'], $policy['security_fixes_until']),
            }, Source::Detected, LaravelSupportPolicy::SOURCE_URL);
        }

        $finding = match ($status) {
            LaravelSupportPolicy::STATUS_END_OF_LIFE => new Finding(
                'env.laravel-end-of-life',
                sprintf('Laravel %d no longer receives security fixes', $major),
                Severity::Medium,
                Confidence::Confirmed,
                $origin,
                'Vulnerabilities discovered in this Laravel version will not be patched upstream. This is a maintenance risk, not proof of a current vulnerability.',
                $policy !== null
                    ? sprintf('Laravel %d security support ended on %s (%s).', $major, $policy['security_fixes_until'], LaravelSupportPolicy::SOURCE_URL)
                    : sprintf('Laravel %d predates the oldest version in the support table (%d).', $major, ProjectDetector::MINIMUM_SUPPORTED_MAJOR),
                'Plan an upgrade to a supported Laravel release. Upgrade one major version at a time, following the official upgrade guides.',
            ),
            LaravelSupportPolicy::STATUS_SECURITY_ONLY => new Finding(
                'env.laravel-security-only',
                sprintf('Laravel %d receives security fixes only', $major),
                Severity::Low,
                Confidence::Confirmed,
                $origin,
                'Bug fixes have ended for this version. Security fixes continue until the date shown.',
                $policy !== null ? sprintf('Bug fixes ended %s; security fixes until %s.', $policy['bug_fixes_until'], $policy['security_fixes_until']) : '',
                'Schedule an upgrade before security support ends.',
            ),
            default => null,
        };

        return [$fact, $finding];
    }

    /**
     * @param list<string> $arguments
     */
    private function toolVersion(InspectionContext $context, string $executable, array $arguments, string $label): ?string
    {
        if (!$context->processes->isAvailable($executable)) {
            return null;
        }
        $result = $context->processes->run(new CommandSpec(
            $executable,
            $arguments,
            sprintf('Read %s version', $label),
            sys_get_temp_dir(),
            min(30, $context->options->processTimeout),
        ));
        if (!$result->isSuccessful()) {
            return null;
        }

        return Versions::extract($result->output);
    }

    private function settingFact(string $label, ConfigValue $value): Fact
    {
        return new Fact($label, $value->display(), $value->source, $value->origin);
    }

    /**
     * @return array{build_tool: string|null, build_tool_origin: string|null, package_manager: string|null, package_manager_origin: string|null, technologies: list<string>}
     */
    private function detectFrontend(InspectionContext $context): array
    {
        $files = $context->files;
        $package = $context->packageJson() ?? [];
        $dependencies = Arr::stringMap($package, 'dependencies') + Arr::stringMap($package, 'devDependencies');

        $buildTool = null;
        $buildOrigin = null;
        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs'] as $file) {
            if ($files->isFile($file)) {
                [$buildTool, $buildOrigin] = ['Vite', $file];
                break;
            }
        }
        if ($buildTool === null && $files->isFile('webpack.mix.js')) {
            [$buildTool, $buildOrigin] = ['Laravel Mix', 'webpack.mix.js'];
        }
        if ($buildTool === null) {
            foreach (['vite' => 'Vite', 'laravel-mix' => 'Laravel Mix', 'webpack' => 'webpack'] as $dependency => $label) {
                if (isset($dependencies[$dependency])) {
                    [$buildTool, $buildOrigin] = [$label, 'package.json'];
                    break;
                }
            }
        }

        $manager = null;
        $managerOrigin = null;
        foreach (['pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'Yarn', 'bun.lockb' => 'Bun', 'bun.lock' => 'Bun', 'package-lock.json' => 'npm'] as $lockFile => $label) {
            if ($files->isFile($lockFile)) {
                [$manager, $managerOrigin] = [$label, $lockFile];
                break;
            }
        }
        if ($manager === null && $package !== []) {
            [$manager, $managerOrigin] = ['npm (no lock file)', 'package.json'];
        }

        $technologies = [];
        foreach (self::FRONTEND_COMPOSER_PACKAGES as $name => $label) {
            if ($context->composer()->lockedProviderOf($name) !== null || isset($context->composer()->require()[$name])) {
                $technologies[] = $label;
            }
        }
        foreach (self::FRONTEND_PACKAGES as $name => $label) {
            if (isset($dependencies[$name])) {
                $technologies[] = $label;
            }
        }

        return [
            'build_tool' => $buildTool,
            'build_tool_origin' => $buildOrigin,
            'package_manager' => $manager,
            'package_manager_origin' => $managerOrigin,
            'technologies' => $technologies,
        ];
    }
}
