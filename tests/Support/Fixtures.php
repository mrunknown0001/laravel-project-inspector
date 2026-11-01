<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Support;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Core\ProjectDetector;
use LaraInspect\Process\ProcessRunnerInterface;
use LaraInspect\Security\PathGuard;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Security\SecretRedactor;

final class Fixtures
{
    public const NOW = '2026-11-01T12:00:00+00:00';

    public static function project(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/projects/' . $name;
    }

    public static function file(string $relative): string
    {
        return dirname(__DIR__) . '/Fixtures/' . $relative;
    }

    public static function json(string $relative): string
    {
        return (string) file_get_contents(self::file($relative));
    }

    public static function reader(string $root): SafeFileReader
    {
        return new SafeFileReader(PathGuard::forDirectory($root), 8_388_608, ['vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git']);
    }

    public static function context(
        string $root,
        ?ProcessRunnerInterface $runner = null,
        bool $offline = false,
        ?SecretRedactor $redactor = null,
        string $now = self::NOW,
    ): InspectionContext {
        $files = self::reader($root);

        return new InspectionContext(
            (new ProjectDetector())->detect($files),
            $files,
            $runner ?? new FakeProcessRunner(),
            $redactor ?? new SecretRedactor(),
            new InspectionOptions(offline: $offline, now: new \DateTimeImmutable($now)),
        );
    }

    /**
     * SHA-256 over every file path and its contents, to prove a directory was not modified.
     */
    public static function fingerprint(string $root): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($root));
            $entries[] = $relative . ':' . ($file->isFile() ? hash_file('sha256', $file->getPathname()) : 'dir') . ':' . $file->getMTime();
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}
