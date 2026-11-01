<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\Exceptions\AIException;
use LaraInspect\AI\Providers\AbstractHttpProvider;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'config:test', description: 'Check an AI provider\'s configuration and send one tiny test request (no project data)')]
final class ConfigTestCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider name (default: AI_PROVIDER)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model to test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('provider');
        $model = $input->getOption('model');

        try {
            $provider = $this->services->aiManager()->provider(is_string($name) ? $name : null, is_string($model) ? $model : null);
        } catch (AIException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
        $settings = $provider instanceof AbstractHttpProvider ? $provider->config() : null;
        $problems = $provider->validateConfiguration();
        if ($problems !== []) {
            $io->error(array_merge([sprintf('%s is not configured correctly:', $settings->label ?? $provider->getProviderName())], $problems));

            return self::FAILURE;
        }

        $io->text(sprintf('Sending a one-line test message to %s (%s, model %s)...', $settings->label ?? $provider->getProviderName(), $settings->baseUrl ?? '', $settings->model ?? ''));
        try {
            $response = $provider->chat(new ChatRequest([ChatMessage::user('Reply with the single word OK.')], maxTokens: 64));
        } catch (AIException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf(
            'Received a response from %s (finish: %s; %s): %s',
            $response->model,
            $response->finishReason,
            $response->usage->describe(),
            mb_substr(trim($response->content), 0, 80) ?: '(empty)',
        ));

        return self::SUCCESS;
    }
}
