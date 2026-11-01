<?php

declare(strict_types=1);

namespace LaraInspect\Support;

use LaraInspect\Analyzers\DependencyAnalyzer;
use LaraInspect\Analyzers\EnvironmentAnalyzer;
use LaraInspect\Core\AnalyzerRegistry;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Core\Inspector;
use LaraInspect\Core\ProjectDetector;
use LaraInspect\Process\AuditLog;
use LaraInspect\Process\ProcessRunnerInterface;
use LaraInspect\Process\SafeProcessRunner;
use LaraInspect\Reports\ReporterRegistry;
use LaraInspect\Security\PathGuard;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Security\SecretRedactor;

/**
 * Composition root. Builds the object graph by hand; no container needed at this size.
 */
final class Services
{
    /** @var (\Closure(AuditLog, InspectionOptions): ProcessRunnerInterface)|null */
    private ?\Closure $processRunnerFactory = null;

    public function __construct(
        public readonly Config $config,
    ) {
    }

    public static function fromBasePath(string $basePath): self
    {
        return new self(Config::load($basePath . '/config/app.php'));
    }

    public function version(): string
    {
        return $this->config->string('version', 'dev');
    }

    public function outputDirectory(): string
    {
        return $this->config->string('output_directory', 'reports');
    }

    /**
     * Replaces the real process runner (used by tests to avoid running external tools).
     *
     * @param \Closure(AuditLog, InspectionOptions): ProcessRunnerInterface $factory
     */
    public function useProcessRunnerFactory(\Closure $factory): void
    {
        $this->processRunnerFactory = $factory;
    }

    public function analyzers(): AnalyzerRegistry
    {
        return new AnalyzerRegistry([
            new EnvironmentAnalyzer(),
            new DependencyAnalyzer(),
        ]);
    }

    public function reporters(): ReporterRegistry
    {
        return ReporterRegistry::default();
    }

    public function options(bool $offline): InspectionOptions
    {
        return new InspectionOptions(
            offline: $offline,
            processTimeout: $this->config->int('process_timeout', 60),
            networkTimeout: $this->config->int('network_process_timeout', 180),
        );
    }

    public function guard(string $path): PathGuard
    {
        return PathGuard::forDirectory($path);
    }

    public function createContext(PathGuard $guard, InspectionOptions $options, AuditLog $audit, SecretRedactor $redactor): InspectionContext
    {
        $files = new SafeFileReader(
            $guard,
            $this->config->int('max_file_bytes', 8_388_608),
            $this->config->strings('excluded_directories'),
        );
        $runner = $this->processRunnerFactory !== null
            ? ($this->processRunnerFactory)($audit, $options)
            : new SafeProcessRunner($audit, $options->processTimeout, offline: $options->offline);

        return new InspectionContext(
            (new ProjectDetector())->detect($files),
            $files,
            $runner,
            $redactor,
            $options,
        );
    }

    public function inspector(AuditLog $audit): Inspector
    {
        return new Inspector($this->version(), $audit);
    }
}
