<?php

declare(strict_types=1);

namespace LaraInspect\Reports;

use LaraInspect\Contracts\ReporterInterface;
use LaraInspect\Exception\InspectorException;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Security\PathGuard;

/**
 * Writes reports and the audit log to the inspector's output directory,
 * and refuses any location inside the inspected project.
 */
final class ReportStore
{
    public const AUDIT_LOG = 'audit.log';

    private function __construct(
        private readonly string $directory,
        private readonly ?string $protectedRoot,
    ) {
    }

    /**
     * The location is validated when something is written, so an explicit --output
     * elsewhere still works when the default directory would be inside the project.
     *
     * @param string      $directory     Output directory (absolute, or relative to $cwd).
     * @param string|null $protectedRoot The inspected project root; nothing may be written beneath it.
     */
    public static function at(string $directory, string $cwd, ?string $protectedRoot = null): self
    {
        return new self(self::absolute($directory, $cwd), $protectedRoot);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function auditLogPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::AUDIT_LOG;
    }

    /**
     * @throws InspectorException When the directory is inside the inspected project or cannot be created.
     */
    public function ensureDirectory(): void
    {
        if ($this->protectedRoot !== null && PathGuard::isWithin(self::normalise($this->directory), $this->protectedRoot)) {
            throw new InspectorException(sprintf(
                'Refusing to write to "%s": it is inside the inspected project. Run the command from another directory, or use --no-save with --output pointing outside the project.',
                $this->directory,
            ));
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new InspectorException(sprintf('Could not create the output directory "%s".', $this->directory));
        }
    }

    /**
     * Default file name for a report: <project>-<timestamp>.<ext>
     */
    public function pathFor(InspectionReport $report, string $extension): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($report->project->root)), '-')) ?: 'project';

        return $this->directory . DIRECTORY_SEPARATOR . sprintf('%s-%s.%s', $slug, $report->generatedAt->format('Ymd-His'), $extension);
    }

    /**
     * Renders and writes a report. Returns the written path.
     *
     * @param string|null $target Explicit file path (absolute or relative to $cwd); defaults to the output directory.
     */
    public function write(InspectionReport $report, ReporterInterface $reporter, ?string $target = null, string $cwd = '.'): string
    {
        if ($target !== null) {
            $path = self::absolute($target, $cwd);
            $this->assertWritable($path);
            $parent = dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0o775, true) && !is_dir($parent)) {
                throw new InspectorException(sprintf('Could not create directory "%s".', $parent));
            }
        } else {
            $this->ensureDirectory();
            $path = $this->pathFor($report, $reporter->fileExtension());
        }

        if (@file_put_contents($path, $reporter->render($report)) === false) {
            throw new InspectorException(sprintf('Could not write the report to "%s".', $path));
        }

        return $path;
    }

    /**
     * The most recently written JSON report in the output directory.
     */
    public function latestJson(): ?string
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => [filemtime($b), $b] <=> [filemtime($a), $a]);

        return $files[0];
    }

    public static function load(string $path): InspectionReport
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InspectorException(sprintf('Report file "%s" does not exist or is not readable.', $path));
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InspectorException(sprintf('Report file "%s" is not valid JSON: %s', $path, $e->getMessage()));
        }
        if (!is_array($data)) {
            throw new InspectorException(sprintf('Report file "%s" does not contain a report.', $path));
        }

        try {
            /** @var array<string, mixed> $data */
            return InspectionReport::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            throw new InspectorException(sprintf('Report file "%s" cannot be loaded: %s', $path, $e->getMessage()));
        }
    }

    private function assertWritable(string $path): void
    {
        if ($this->protectedRoot !== null && PathGuard::isWithin(self::normalise($path), $this->protectedRoot)) {
            throw new InspectorException(sprintf('Refusing to write "%s": it is inside the inspected project.', $path));
        }
    }

    private static function absolute(string $path, string $cwd): string
    {
        return PathGuard::isAbsolute($path) ? $path : rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Resolves "." and ".." lexically, then realpath()s the deepest existing ancestor
     * so a symlink cannot disguise a location inside the protected project.
     */
    private static function normalise(string $path): string
    {
        $prefix = preg_match('#^([A-Za-z]:)?[/\\\\]#', $path, $m) === 1 ? $m[0] : '';
        $segments = [];
        foreach (preg_split('#[/\\\\]+#', substr($path, strlen($prefix))) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $remaining = [];
        while (true) {
            $candidate = $prefix . implode(DIRECTORY_SEPARATOR, $segments);
            $real = $candidate === '' ? false : realpath($candidate);
            if ($real !== false) {
                return $remaining === [] ? $real : $real . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $remaining);
            }
            if ($segments === []) {
                return $path;
            }
            array_unshift($remaining, (string) array_pop($segments));
        }
    }
}
