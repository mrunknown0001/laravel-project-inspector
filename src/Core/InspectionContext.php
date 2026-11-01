<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Composer\ComposerProject;
use LaraInspect\Exception\FileAccessException;
use LaraInspect\Parsing\ConfigReader;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Process\ProcessRunnerInterface;
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

    /** @var array<mixed>|false|null false = not loaded yet */
    private array|false|null $packageJson = false;

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
