<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\Security\SecretPatterns;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config:show', description: 'Show the inspector\'s configuration and where each value comes from (API keys masked)')]
final class ConfigShowCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->services->config;

        $io->section('Inspector');
        $io->definitionList(
            ['Version' => $this->services->version()],
            ['Base directory' => $this->services->basePath],
            ['Settings file' => $this->services->aiConfig()->settingsFilePath() ?? 'none (run "larainspect config:init")'],
            ['Output directory' => './' . $this->services->outputDirectory()],
            ['Process timeout' => $config->int('process_timeout') . ' s'],
            ['Network timeout' => $config->int('network_process_timeout') . ' s'],
        );

        $io->section('AI');
        $rows = [];
        foreach ($this->services->aiConfig()->describe() as [$key, $value, $source]) {
            if ($value === null && $source === 'default') {
                continue;
            }
            $display = $value === null ? '' : (str_ends_with($key, '_API_KEY') ? SecretPatterns::mask($value) : (new SecretRedactor())->redact($value));
            $rows[] = [$key, $display, $source];
        }
        $io->table(['Setting', 'Value', 'Source'], $rows);
        $io->text('Sources: environment variables override the inspector\'s .env file, which overrides the defaults.');

        return self::SUCCESS;
    }
}
