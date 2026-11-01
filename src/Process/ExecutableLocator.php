<?php

declare(strict_types=1);

namespace LaraInspect\Process;

/**
 * Finds executables on PATH, considering only absolute PATH entries.
 *
 * An empty or relative PATH entry means "the current directory". If the
 * inspector is run from inside an inspected project, that would let the
 * project's own ./git or ./composer run without confirmation, so such
 * entries are ignored.
 */
final class ExecutableLocator
{
    public function __construct(
        private readonly ?string $path = null,
        private readonly ?bool $windows = null,
    ) {
    }

    public function find(string $name): ?string
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            return null;
        }
        $windows = $this->windows ?? DIRECTORY_SEPARATOR === '\\';
        $separator = $windows ? ';' : ':';
        $extensions = $windows
            ? array_merge([''], array_map('strtolower', array_filter(explode(';', (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD')))))
            : [''];

        foreach (explode($separator, $this->path ?? (string) getenv('PATH')) as $directory) {
            $directory = trim($directory, " \t\"");
            if (!self::isAbsolute($directory, $windows)) {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
                if (is_file($candidate) && ($windows || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function isAbsolute(string $directory, bool $windows): bool
    {
        if ($directory === '') {
            return false;
        }

        return $windows ? preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\)#', $directory) === 1 : str_starts_with($directory, '/');
    }
}
