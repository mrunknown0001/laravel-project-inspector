<?php

declare(strict_types=1);

namespace LaraInspect\Security;

use LaraInspect\Exception\InvalidProjectException;
use LaraInspect\Exception\UnsafePathException;

/**
 * Confines every file access to the inspected project root.
 *
 * Relative paths are normalised without touching the filesystem, so ".." can
 * never climb above the root. Paths that exist are then resolved with realpath()
 * to catch symlinks that point outside the project.
 */
final class PathGuard
{
    private function __construct(
        private readonly string $root,
    ) {
    }

    public static function forDirectory(string $path): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidProjectException('A project path is required.');
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new InvalidProjectException(sprintf('Directory "%s" does not exist.', $path));
        }
        if (!is_readable($real)) {
            throw new InvalidProjectException(sprintf('Directory "%s" is not readable.', $path));
        }

        return new self(rtrim($real, '/\\') ?: $real);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolves a project-relative path to an absolute path inside the root.
     *
     * @throws UnsafePathException
     */
    public function resolve(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new UnsafePathException('Path contains a null byte.');
        }
        if (self::isAbsolute($relative)) {
            throw new UnsafePathException(sprintf('Absolute paths are not accepted: "%s".', $relative));
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]+#', $relative) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new UnsafePathException(sprintf('Path "%s" escapes the project root.', $relative));
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $absolute = $segments === []
            ? $this->root
            : $this->root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);

        // Existing paths may be symlinks; make sure their real target stays inside the root.
        if (file_exists($absolute) || is_link($absolute)) {
            $real = realpath($absolute);
            if ($real === false || !$this->contains($real)) {
                throw new UnsafePathException(sprintf('Path "%s" resolves outside the project root.', $relative));
            }
        }

        return $absolute;
    }

    /**
     * Whether an absolute path is the root itself or lies beneath it.
     */
    public function contains(string $absolute): bool
    {
        return self::isWithin($absolute, $this->root);
    }

    /**
     * Converts an absolute path inside the root to a forward-slash relative path.
     */
    public function relative(string $absolute): string
    {
        if (!$this->contains($absolute)) {
            throw new UnsafePathException(sprintf('Path "%s" is outside the project root.', $absolute));
        }

        return str_replace('\\', '/', ltrim(substr($absolute, strlen($this->root)), '/\\'));
    }

    /**
     * Whether $path equals $directory or lies beneath it. Case-insensitive on Windows.
     */
    public static function isWithin(string $path, string $directory): bool
    {
        $normalise = static function (string $p): string {
            $p = rtrim(str_replace('\\', '/', $p), '/');

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
        };
        $path = $normalise($path);
        $directory = $normalise($directory);

        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:#', $path) === 1
            || str_contains($path, '://');
    }
}
