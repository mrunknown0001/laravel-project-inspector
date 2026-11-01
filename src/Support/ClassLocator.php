<?php

declare(strict_types=1);

namespace LaraInspect\Support;

use LaraInspect\Composer\ComposerProject;
use LaraInspect\Security\SafeFileReader;

/**
 * Maps project class names to files using composer.json PSR-4 rules (no autoloader is loaded).
 */
final class ClassLocator
{
    /** @var array<string, list<string>> prefix => directories, longest prefix first */
    private readonly array $map;

    public function __construct(
        private readonly SafeFileReader $files,
        ComposerProject $composer,
    ) {
        $map = $composer->psr4() + $composer->psr4(true);
        if ($map === []) {
            $map = ['App\\' => ['app']];
        }
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->map = $map;
    }

    /**
     * Whether a class belongs to the project's own namespaces (not vendor).
     */
    public function isProjectClass(string $class): bool
    {
        return $this->prefixFor(ltrim($class, '\\')) !== null;
    }

    public function locate(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $prefix = $this->prefixFor($class);
        if ($prefix === null) {
            return null;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($this->map[$prefix] as $directory) {
            $path = ($directory === '' ? '' : $directory . '/') . $relative;
            if ($this->files->isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    private function prefixFor(string $class): ?string
    {
        foreach (array_keys($this->map) as $prefix) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }
}
