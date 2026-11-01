<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Composer\ComposerAudit;
use LaraInspect\Composer\ComposerAuditResult;
use LaraInspect\Composer\ComposerProject;
use LaraInspect\Exception\FileAccessException;
use LaraInspect\Parsing\ConfigReader;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ProcessRunnerInterface;
use LaraInspect\Routing\RouteCollection;
use LaraInspect\Routing\RouteLoader;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Security\SecretRedactor;

/**
 * Everything an analyzer may use. Parsed project files are loaded lazily and shared.
 */
final class InspectionContext
{
    private ?ComposerProject $composer = null;
    private ?EnvFile $env = null;
    private ?ConfigReader $config = null;

    private ?ComposerAuditResult $composerAudit = null;
    private ?RouteCollection $routes = null;

    /** @var array<mixed>|false|null false = not loaded yet */
    private array|false|null $packageJson = false;

    /** @var list<string>|false|null false = not loaded yet, null = unknown (not a git checkout) */
    private array|false|null $trackedFiles = false;

    /** Conventional Laravel source directories; PSR-4 roots are added at runtime. */
    private const SOURCE_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'lang'];

    public function __construct(
        public readonly ProjectInfo $project,
        public readonly SafeFileReader $files,
        public readonly ProcessRunnerInterface $processes,
        public readonly SecretRedactor $redactor,
        public readonly InspectionOptions $options = new InspectionOptions(),
    ) {
    }

    public function root(): string
    {
        return $this->files->root();
    }

    public function composer(): ComposerProject
    {
        return $this->composer ??= new ComposerProject($this->files);
    }

    /**
     * The project's .env, falling back to .env.example (whose values count as inferred).
     */
    public function env(): EnvFile
    {
        if ($this->env !== null) {
            return $this->env;
        }

        foreach (['.env', '.env.example'] as $file) {
            $contents = $this->files->readIfExists($file);
            if ($contents !== null) {
                $env = EnvFile::fromContents($contents, $file);
                $this->redactor->addKnownSecrets($env->secretValuesForRedaction());

                return $this->env = $env;
            }
        }

        return $this->env = EnvFile::empty();
    }

    public function config(): ConfigReader
    {
        return $this->config ??= new ConfigReader($this->files, $this->env());
    }

    /**
     * "composer audit" + "composer outdated", run at most once per inspection.
     */
    public function composerAudit(): ComposerAuditResult
    {
        return $this->composerAudit ??= (new ComposerAudit(
            $this->processes,
            $this->root(),
            $this->options->offline,
            $this->options->networkTimeout,
        ))->run($this->composer());
    }

    /**
     * Registered routes, parsed statically (or from artisan when explicitly allowed).
     */
    public function routes(): RouteCollection
    {
        return $this->routes ??= (new RouteLoader())->load($this);
    }

    /**
     * Files tracked by git, relative to the project root; null when unknown.
     *
     * git runs with fsmonitor disabled so a hostile repository config cannot
     * make it execute commands, and safe.directory protections are respected.
     *
     * @return list<string>|null
     */
    public function trackedFiles(): ?array
    {
        if ($this->trackedFiles !== false) {
            return $this->trackedFiles;
        }
        if (!$this->processes->isAvailable('git')) {
            return $this->trackedFiles = null;
        }

        $result = $this->processes->run(new CommandSpec(
            'git',
            ['-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false', '-C', $this->root(), 'ls-files', '-z', '--cached'],
            'List files tracked by git (to tell committed files from local ones)',
            sys_get_temp_dir(),
            $this->options->processTimeout,
        ));
        if (!$result->isSuccessful()) {
            return $this->trackedFiles = null;
        }

        $files = array_values(array_filter(explode("\0", $result->output), static fn (string $f): bool => $f !== ''));
        sort($files);

        return $this->trackedFiles = $files;
    }

    public function isTracked(string $relative): ?bool
    {
        $tracked = $this->trackedFiles();

        return $tracked === null ? null : in_array($relative, $tracked, true);
    }

    /**
     * Directories holding the application's own code (never vendor/ or node_modules/).
     *
     * @return list<string>
     */
    public function sourceDirectories(): array
    {
        $directories = self::SOURCE_DIRECTORIES;
        foreach ($this->composer()->psr4() as $dirs) {
            array_push($directories, ...$dirs);
        }

        return array_values(array_filter(
            array_unique($directories),
            fn (string $dir): bool => !$this->files->isExcluded($dir) && $this->files->isDirectory($dir),
        ));
    }

    /**
     * Source files with the given extensions across all source directories.
     *
     * @param list<string>      $extensions
     * @param list<string>|null $directories Defaults to {@see sourceDirectories()}.
     * @return list<string>
     */
    public function sourceFiles(array $extensions, ?array $directories = null): array
    {
        $files = [];
        foreach ($directories ?? $this->sourceDirectories() as $directory) {
            array_push($files, ...$this->files->findFiles($directory, $extensions));
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return array<mixed>|null Decoded package.json, or null if absent or invalid.
     */
    public function packageJson(): ?array
    {
        if ($this->packageJson === false) {
            try {
                $this->packageJson = $this->files->readJson('package.json');
            } catch (FileAccessException) {
                $this->packageJson = null;
            }
        }

        return $this->packageJson;
    }
}
