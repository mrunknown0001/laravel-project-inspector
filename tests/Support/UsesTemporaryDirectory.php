<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * Gives a test its own scratch directory, removed afterwards.
 */
trait UsesTemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    protected function tempDir(): string
    {
        if ($this->temporaryDirectory === null) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'larainspect-test-' . bin2hex(random_bytes(6));
            mkdir($path, 0o777, true);
            $this->temporaryDirectory = (string) realpath($path);
        }

        return $this->temporaryDirectory;
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    protected function makeProject(array $files): string
    {
        $root = $this->tempDir() . DIRECTORY_SEPARATOR . 'project-' . bin2hex(random_bytes(3));
        foreach ($files as $path => $contents) {
            $full = $root . DIRECTORY_SEPARATOR . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $contents);
        }
        if (!is_dir($root)) {
            mkdir($root, 0o777, true);
        }

        return (string) realpath($root);
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory !== null && is_dir($this->temporaryDirectory)) {
            self::deleteTree($this->temporaryDirectory);
        }
        $this->temporaryDirectory = null;
    }

    private static function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::deleteTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
