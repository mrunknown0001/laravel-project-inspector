<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config:init', description: 'Create the inspector\'s .env from .env.example (never overwrites an existing file)')]
final class ConfigInitCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $this->services->basePath . DIRECTORY_SEPARATOR . '.env';
        $example = $this->services->basePath . DIRECTORY_SEPARATOR . '.env.example';

        if (file_exists($target)) {
            $io->note(sprintf('%s already exists and was left unchanged.', $target));

            return self::SUCCESS;
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
