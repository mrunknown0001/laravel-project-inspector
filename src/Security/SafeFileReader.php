<?php

declare(strict_types=1);

namespace LaraInspect\Security;

use LaraInspect\Exception\FileAccessException;
use LaraInspect\Exception\UnsafePathException;

/**
 * Read-only access to files in the inspected project.
 *
 * This class has no write methods by design. All paths are project-relative
 * and pass through {@see PathGuard}.
 */
final class SafeFileReader
{
    /** @var list<string> */
    private readonly array $excludedPrefixes;

    /** @var list<string> */
    private readonly array $excludedSegments;

    /**
     * @param list<string> $excludedDirectories Entries containing "/" match from the root
     *                                          (e.g. "bootstrap/cache"); others match any path segment.
     */
    public function __construct(
        private readonly PathGuard $guard,
        private readonly int $maxBytes = 8_388_608,
        array $excludedDirectories = [],
    ) {
        $prefixes = [];
        $segments = [];
        foreach ($excludedDirectories as $dir) {
            $dir = trim(str_replace('\\', '/', $dir), '/');
            if ($dir === '') {
                continue;
            }
            if (str_contains($dir, '/')) {
                $prefixes[] = $dir;
            } else {
                $segments[] = $dir;
            }
        }
        $this->excludedPrefixes = $prefixes;
        $this->excludedSegments = $segments;
    }

    public function guard(): PathGuard
    {
        return $this->guard;
    }

    public function root(): string
    {
        return $this->guard->root();
    }

    public function exists(string $relative): bool
    {
        return $this->safeResolve($relative) !== null;
    }

    public function isFile(string $relative): bool
    {
        $path = $this->safeResolve($relative);

        return $path !== null && is_file($path);
    }

    public function isDirectory(string $relative): bool
    {
        $path = $this->safeResolve($relative);

        return $path !== null && is_dir($path);
    }

    /**
     * @param int|null $maxBytes Lower per-call limit, e.g. for bulk source scans.
     *
     * @throws FileAccessException
     */
    public function read(string $relative, ?int $maxBytes = null): string
    {
        $limit = min($maxBytes ?? $this->maxBytes, $this->maxBytes);
        try {
            $path = $this->guard->resolve($relative);
        } catch (UnsafePathException $e) {
            throw new FileAccessException($e->getMessage(), 0, $e);
        }

        if (!is_file($path)) {
            throw new FileAccessException(sprintf('File "%s" does not exist.', $relative));
        }
        $size = filesize($path);
        if ($size === false || $size > $limit) {
            throw new FileAccessException(sprintf(
                'File "%s" is larger than the %d byte read limit.',
                $relative,
                $limit,
            ));
        }

        $contents = @file_get_contents($path, false, null, 0, $limit);
        if ($contents === false) {
            throw new FileAccessException(sprintf('File "%s" could not be read.', $relative));
        }

        return $contents;
    }

    public function readIfExists(string $relative): ?string
    {
        if (!$this->isFile($relative)) {
            return null;
        }

        try {
            return $this->read($relative);
        } catch (FileAccessException) {
            return null;
        }
    }

    /**
     * Decodes a JSON file. Returns null when the file does not exist.
     *
     * @return array<mixed>|null
     *
     * @throws FileAccessException When the file exists but is not a JSON object/array.
     */
    public function readJson(string $relative): ?array
    {
        if (!$this->isFile($relative)) {
            return null;
        }

        try {
            $decoded = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FileAccessException(sprintf('File "%s" is not valid JSON: %s.', $relative, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new FileAccessException(sprintf('File "%s" does not contain a JSON object.', $relative));
        }

        return $decoded;
    }

    /**
     * Lists files beneath a directory, skipping excluded and symlinked directories.
     *
     * @param list<string> $extensions e.g. ['php']; empty for all files.
     * @return list<string> Forward-slash paths relative to the project root, sorted.
     */
    public function findFiles(string $directory, array $extensions = [], bool $recursive = true, int $limit = 20_000): array
    {
        $start = $this->safeResolve($directory);
        if ($start === null || !is_dir($start) || $this->isExcluded($this->guard->relative($start))) {
            return [];
        }

        $results = [];
        $pending = [$start];
        while ($pending !== [] && count($results) < $limit) {
            $current = array_pop($pending);
            $entries = @scandir($current);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current . DIRECTORY_SEPARATOR . $entry;
                $relative = $this->guard->relative($path);

                if (is_link($path)) {
                    // Never follow links: they may point outside the project or create cycles.
                    continue;
                }
                if (is_dir($path)) {
                    if ($recursive && !$this->isExcluded($relative)) {
                        $pending[] = $path;
                    }
                    continue;
                }
                if ($extensions !== [] && !in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                $results[] = $relative;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }
        sort($results);

        return $results;
    }

    public function isExcluded(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return false;
        }
        foreach ($this->excludedPrefixes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                return true;
            }
        }

        return array_intersect(explode('/', $relative), $this->excludedSegments) !== [];
    }

    private function safeResolve(string $relative): ?string
    {
        try {
            $path = $this->guard->resolve($relative);
        } catch (UnsafePathException) {
            return null;
        }

        return file_exists($path) ? $path : null;
    }
}
