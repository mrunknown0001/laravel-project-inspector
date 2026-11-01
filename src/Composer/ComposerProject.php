<?php

declare(strict_types=1);

namespace LaraInspect\Composer;

use LaraInspect\Exception\FileAccessException;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Support\Arr;

/**
 * Parsed composer.json, composer.lock and vendor/composer/installed.json of the target project.
 *
 * Parse errors are captured rather than thrown so analyzers can report them as findings.
 */
final class ComposerProject
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, mixed>|null */
    private ?array $lock = null;

    /** @var array<string, LockedPackage>|null */
    private ?array $locked = null;

    /** @var array<string, LockedPackage>|null */
    private ?array $installed = null;

    /** @var array<string, string> file => error message */
    private array $errors = [];

    private bool $loaded = false;

    public function __construct(
        private readonly SafeFileReader $files,
    ) {
    }

    public function hasManifest(): bool
    {
        $this->load();

        return $this->manifest !== null;
    }

    public function hasLock(): bool
    {
        $this->load();

        return $this->lock !== null;
    }

    public function hasInstalledMetadata(): bool
    {
        $this->load();

        return $this->installed !== null;
    }

    /**
     * @return array<string, string> file => parse error
     */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $this->load();

        return $this->manifest ?? [];
    }

    public function name(): ?string
    {
        $name = Arr::string($this->manifest(), 'name');

        return $name === '' ? null : $name;
    }

    /**
     * @return array<string, string>
     */
    public function require(): array
    {
        return Arr::stringMap($this->manifest(), 'require');
    }

    /**
     * @return array<string, string>
     */
    public function requireDev(): array
    {
        return Arr::stringMap($this->manifest(), 'require-dev');
    }

    /**
     * Package requirements without platform entries (php, ext-*, lib-*, composer-*).
     *
     * @return array<string, string>
     */
    public function directPackages(bool $dev = false): array
    {
        return array_filter(
            $dev ? $this->requireDev() : $this->require(),
            static fn (string $name): bool => !self::isPlatformPackage($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, string>
     */
    public function platformRequirements(): array
    {
        return array_filter(
            $this->require() + $this->requireDev(),
            static fn (string $name): bool => self::isPlatformPackage($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function phpConstraint(): ?string
    {
        return $this->require()['php'] ?? null;
    }

    /**
     * PHP version pinned with config.platform.php, if any.
     */
    public function platformPhpOverride(): ?string
    {
        $platform = Arr::array(Arr::array($this->manifest(), 'config'), 'platform');
        $php = $platform['php'] ?? null;

        return is_string($php) && $php !== '' ? $php : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> */
        return Arr::array($this->manifest(), 'config');
    }

    /**
     * @return array<string, mixed>
     */
    public function lock(): array
    {
        $this->load();

        return $this->lock ?? [];
    }

    /**
     * @return array<string, LockedPackage> name => package (production and dev)
     */
    public function lockedPackages(): array
    {
        $this->load();

        return $this->locked ?? [];
    }

    public function lockedPackage(string $name): ?LockedPackage
    {
        return $this->lockedPackages()[strtolower($name)] ?? null;
    }

    /**
     * The locked package that satisfies a requirement directly, or by replace/provide.
     */
    public function lockedProviderOf(string $name): ?LockedPackage
    {
        $name = strtolower($name);
        $direct = $this->lockedPackage($name);
        if ($direct !== null) {
            return $direct;
        }
        foreach ($this->lockedPackages() as $package) {
            if (in_array($name, $package->provides, true)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @return array<string, LockedPackage> name => package, from vendor/composer/installed.json
     */
    public function installedPackages(): array
    {
        $this->load();

        return $this->installed ?? [];
    }

    public static function isPlatformPackage(string $name): bool
    {
        $name = strtolower($name);

        return $name === 'php'
            || $name === 'php-64bit'
            || $name === 'hhvm'
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-')
            || str_starts_with($name, 'composer-');
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        /** @var array<string, mixed>|null $manifest */
        $manifest = $this->readJson('composer.json');
        $this->manifest = $manifest;

        /** @var array<string, mixed>|null $lock */
        $lock = $this->readJson('composer.lock');
        $this->lock = $lock;
        if ($lock !== null) {
            $this->locked = [];
            foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
                foreach (Arr::listOfArrays($lock, $key) as $entry) {
                    $package = LockedPackage::fromArray($entry, $dev);
                    if ($package->name !== '') {
                        $this->locked[strtolower($package->name)] = $package;
                    }
                }
            }
        }

        $installed = $this->readJson('vendor/composer/installed.json');
        if ($installed !== null) {
            $this->installed = [];
            // Composer 2 wraps the list in {"packages": [...], "dev-package-names": [...]}; Composer 1 is a bare list.
            $entries = array_is_list($installed) ? $installed : Arr::array($installed, 'packages');
            $devNames = array_map('strtolower', Arr::strings($installed, 'dev-package-names'));
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                /** @var array<string, mixed> $entry */
                $name = strtolower(Arr::string($entry, 'name'));
                if ($name !== '') {
                    $this->installed[$name] = LockedPackage::fromArray($entry, in_array($name, $devNames, true));
                }
            }
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $file): ?array
    {
        try {
            return $this->files->readJson($file);
        } catch (FileAccessException $e) {
            $this->errors[$file] = $e->getMessage();

            return null;
        }
    }
}
