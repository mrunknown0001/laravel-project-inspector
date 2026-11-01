<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\AI\Exceptions\AIException;
use LaraInspect\AI\Providers\AbstractHttpProvider;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai:providers', description: 'List AI providers, their configuration status and (with --check) whether they are reachable')]
final class AiProvidersCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Call each configured provider\'s model list endpoint (network; sends no inspection data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->services->aiConfig();
        $manager = $this->services->aiManager();
        $default = $config->defaultProvider();
        $check = (bool) $input->getOption('check');

        $rows = [];
        $notes = [];
        foreach ($manager->providerNames() as $name) {
            $provider = $manager->provider($name);
            $settings = $provider instanceof AbstractHttpProvider ? $provider->config() : null;
            $problems = $provider->validateConfiguration();
            $status = $problems === [] ? '<info>ready</info>' : '<comment>' . implode(' ', $problems) . '</comment>';

            if ($check && $settings !== null && $settings->hasApiKey()) {
                try {
                    $status .= sprintf(' / reachable (%d models)', count($provider->listModels()));
                } catch (AIException $e) {
                    $status .= ' / <error>' . OutputFormatter::escape($e->getMessage()) . '</error>';
                }
            }

            $rows[] = [
                ($name === $default ? '* ' : '  ') . $name,
                $settings?->maskedKey() ?? '-',
                $settings->baseUrl ?? '-',
                $settings->model ?? '-',
                $status,
            ];
            $note = $config->definition($name)['note'] ?? null;
            if (is_string($note)) {
                $notes[] = sprintf('%s: %s', $config->definition($name)['label'] ?? $name, $note);
            }
        }

        $io->table(['Provider', 'API key', 'Base URL', 'Model', 'Status'], $rows);
        $io->text($default !== null ? sprintf('* default provider (AI_PROVIDER=%s)', $default) : 'No default provider: set AI_PROVIDER or pass --provider.');
        foreach ($notes as $note) {
            $io->note($note);
        }

        return self::SUCCESS;
    }
}
