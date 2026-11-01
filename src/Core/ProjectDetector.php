<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Composer\ComposerProject;
use LaraInspect\Model\Source;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Support\Versions;

/**
 * Decides whether a directory is a Laravel application and which version it runs.
 *
 * Version sources, most reliable first:
 *  1. composer.lock entry for laravel/framework
 *  2. vendor/composer/installed.json
 *  3. Application::VERSION constant in vendor (read as text, never loaded)
 *  4. composer.json constraint (inferred major only)
 */
final class ProjectDetector
{
    public const MINIMUM_SUPPORTED_MAJOR = 8;

    public const MARKERS = ['artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'app', 'routes', 'config'];

    private const FRAMEWORK_FILE = 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php';

    public function detect(SafeFileReader $files, ?ComposerProject $composer = null): ProjectInfo
    {
        $composer ??= new ComposerProject($files);
        $warnings = [];

        $markers = [];
        foreach (self::MARKERS as $marker) {
            $markers[$marker] = $files->exists($marker);
        }

        foreach ($composer->errors() as $file => $error) {
            if ($file !== 'vendor/composer/installed.json') {
                $warnings[] = $error;
            }
        }

        $requiresLaravel = isset($composer->require()['laravel/framework']) || $composer->lockedPackage('laravel/framework') !== null;
        $requiresLumen = isset($composer->require()['laravel/lumen-framework']) || $composer->lockedPackage('laravel/lumen-framework') !== null;

        $framework = match (true) {
            $requiresLaravel => 'laravel',
            $requiresLumen => 'lumen',
            default => null,
        };

        $isLaravel = $requiresLaravel && !$requiresLumen && ($markers['artisan'] || $markers['bootstrap/app.php']);

        if ($requiresLumen) {
            $warnings[] = 'This is a Lumen application. Lumen is not supported; results may be incomplete.';
        } elseif (!$requiresLaravel && $markers['artisan'] && $markers['bootstrap/app.php']) {
            $warnings[] = 'The directory looks like a Laravel application, but composer.json does not require laravel/framework.';
        } elseif ($requiresLaravel && !$isLaravel) {
            $warnings[] = 'laravel/framework is required, but artisan and bootstrap/app.php are missing; this may be a package rather than an application.';
        }

        [$version, $major, $source, $origin] = $this->detectVersion($files, $composer, $requiresLumen ? 'laravel/lumen-framework' : 'laravel/framework');

        if ($major !== null && $major < self::MINIMUM_SUPPORTED_MAJOR && $isLaravel) {
            $warnings[] = sprintf(
                'Laravel %d is older than the minimum supported version (%d); inspection is best-effort.',
                $major,
                self::MINIMUM_SUPPORTED_MAJOR,
            );
        }

        return new ProjectInfo(
            root: $files->root(),
            name: $composer->name() ?? basename($files->root()),
            isLaravel: $isLaravel,
            framework: $framework,
            laravelVersion: $version,
            laravelMajor: $major,
            versionSource: $source,
            versionOrigin: $origin,
            layout: $this->detectLayout($files),
            markers: $markers,
            warnings: $warnings,
        );
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: Source, 3: string|null}
     */
    private function detectVersion(SafeFileReader $files, ComposerProject $composer, string $package): array
    {
        $locked = $composer->lockedPackage($package);
        if ($locked !== null && !str_starts_with($locked->version, 'dev-')) {
            $version = Versions::clean($locked->version);

            return [$version, Versions::major($version), Source::Detected, 'composer.lock'];
        }

        $installed = $composer->installedPackages()[$package] ?? null;
        if ($installed !== null && !str_starts_with($installed->version, 'dev-')) {
            $version = Versions::clean($installed->version);

            return [$version, Versions::major($version), Source::Detected, 'vendor/composer/installed.json'];
        }

        if ($package === 'laravel/framework') {
            $source = $files->readIfExists(self::FRAMEWORK_FILE);
            if ($source !== null && preg_match("/const\\s+VERSION\\s*=\\s*'([^']+)'/", $source, $m) === 1) {
                return [$m[1], Versions::major($m[1]), Source::Detected, self::FRAMEWORK_FILE];
            }
        }

        $constraint = $composer->require()[$package] ?? null;
        if ($constraint !== null) {
            $major = Versions::constraintLowestMajor($constraint);
            if ($major !== null) {
                return [$major . '.x', $major, Source::Inferred, sprintf('composer.json constraint "%s"', $constraint)];
            }
        }

        return [null, null, Source::Unknown, null];
    }

    private function detectLayout(SafeFileReader $files): string
    {
        $bootstrap = $files->readIfExists('bootstrap/app.php') ?? '';
        if (str_contains($bootstrap, 'Application::configure(')) {
            return ProjectInfo::LAYOUT_MODERN;
        }
        if ($files->isFile('app/Http/Kernel.php') || str_contains($bootstrap, 'new Illuminate\\Foundation\\Application')) {
            return ProjectInfo::LAYOUT_LEGACY;
        }

        return ProjectInfo::LAYOUT_UNKNOWN;
    }
}
