<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\AI\AIConfig;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config:init', description: 'Create the inspector settings file from .env.example (never overwrites an existing file)')]
final class ConfigInitCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_NONE, 'Create the per-user file (~/.config/larainspect/.env, %APPDATA%\\larainspect\\.env on Windows) instead of .env in the inspector directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $base = $this->services->basePath;
        $example = $base . DIRECTORY_SEPARATOR . '.env.example';

        // Under vendor/ a local .env would be lost on "composer update".
        $user = (bool) $input->getOption('user') || AIConfig::installedAsDependency($base);
        $environment = array_filter(getenv(), static fn (string $v): bool => $v !== '');
        $target = $user ? AIConfig::userSettingsPath($environment) : $base . DIRECTORY_SEPARATOR . '.env';
        if ($target === null) {
            $io->error('Could not determine the per-user settings directory (HOME/APPDATA is not set). Set LARAINSPECT_ENV_FILE instead.');

            return self::FAILURE;
        }

        if (file_exists($target)) {
            $io->note(sprintf('%s already exists and was left unchanged.', $target));

            return self::SUCCESS;
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            $io->error(sprintf('Could not create %s.', $directory));

            return self::FAILURE;
        }
        if (!is_file($example) || !@copy($example, $target)) {
            $io->error(sprintf('Could not create %s from %s.', $target, $example));

            return self::FAILURE;
        }
        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($target, 0o600); // the file will hold API keys
        }

        $io->success(sprintf('Created %s. Add an API key and set AI_PROVIDER, then run "larainspect config:test".', $target));

        return self::SUCCESS;
    }
}
