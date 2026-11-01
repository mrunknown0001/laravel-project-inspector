<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\AI\Exceptions\AIException;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai:models', description: 'List the models a provider offers (calls the provider\'s model list endpoint)')]
final class AiModelsCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider name (default: AI_PROVIDER)')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only show model ids containing this text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('provider');
        $filter = $input->getOption('filter');

        try {
            $provider = $this->services->aiManager()->provider(is_string($name) ? $name : null);
            $models = $provider->listModels();
        } catch (AIException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if (is_string($filter) && $filter !== '') {
            $models = array_values(array_filter($models, static fn ($m): bool => stripos($m->id, $filter) !== false));
        }
        $io->table(['Model id', 'Name', 'Context window'], array_map(static fn ($m): array => [
            $m->id,
            $m->name ?? '',
            $m->contextWindow !== null ? number_format($m->contextWindow) : '',
        ], $models));
        $io->text(sprintf('%d models. Use one with --model=<id> or %s_MODEL.', count($models), strtoupper($provider->getProviderName())));

        return self::SUCCESS;
    }
}
