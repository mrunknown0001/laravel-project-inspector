<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\AI\AIAnalyzer;
use LaraInspect\AI\PromptBuilder;
use LaraInspect\AI\Tasks\TaskCatalog;
use LaraInspect\Exception\InspectorException;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\Severity;
use LaraInspect\Reports\ConsoleReporter;
use LaraInspect\Reports\ReporterRegistry;
use LaraInspect\Reports\ReportStore;
use LaraInspect\Security\SecretRedactor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai',
    description: 'AI-assisted analysis of inspection results (explanations, remediation plans, documentation)',
)]
final class AiCommand extends AbstractInspectionCommand
{
    protected function pathIsRequired(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Use saved results instead of inspecting (default without a path: the latest saved results)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print exactly what would be sent to the provider, and send nothing')
            ->setHelp(<<<'HELP'
                Runs AI tasks over inspection results. The AI receives only the redacted
                results (findings, facts, summaries), never source code or .env files.

                  <info>larainspect ai /path/to/project --provider=anthropic</info>
                  <info>larainspect ai --from=reports/app-20261101-120000.json --ai-task=documentation</info>
                  <info>larainspect ai ./app --provider=openrouter --model=anthropic/claude-opus-5.5 --ai-task=all</info>
                  <info>larainspect ai ./app --dry-run</info>     (review the prompts first; nothing is sent)

                Tasks: explain-findings, architecture, dependencies, security-fixes,
                documentation, technical-debt, best-practices (default: explain-findings,
                security-fixes, technical-debt). Each task is one API request.
                HELP);
    }

    protected function configureAiOptions(): void
    {
        parent::configureAiOptions();
        // "ai" always runs AI; --ai / --no-ai only make sense on other commands.
        $definition = $this->getDefinition();
        $options = $definition->getOptions();
        unset($options['ai'], $options['no-ai']);
        $definition->setOptions($options);
    }

    protected function wantsAi(InputInterface $input): bool
    {
        return true;
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $format = $this->services->reporters()->normalise(self::stringOption($input, 'format') ?? $this->defaultFormat());
        $failOn = self::stringOption($input, 'fail-on');
        $path = $input->getArgument('path');
        $from = self::stringOption($input, 'from');

        if (is_string($path) && $path !== '') {
            if ($from !== null) {
                throw new \InvalidArgumentException('Pass either a project path or --from, not both.');
            }
            $report = $this->inspect($path, $input, $output, $io);
        } else {
            $from ??= ReportStore::at($this->services->outputDirectory(), (string) getcwd())->latestJson()
                ?? throw new InspectorException('No saved results found. Pass a project path, or run "larainspect inspect <path>" first.');
            $report = ReportStore::load($from);
            $this->progressOutput($output)?->writeln(sprintf('<fg=gray>Using saved results from %s</>', $from));
        }

        if ((bool) $input->getOption('dry-run')) {
            $this->dryRun($report, $input, $io);

            return self::SUCCESS;
        }

        $report = $this->runAi($report, $input, $output);
        $this->save($report, $input, $output);

        if ($format === ReporterRegistry::CONSOLE && self::stringOption($input, 'output') === null) {
            (new ConsoleReporter())->insights($report, $io);
        } else {
            $this->deliver($report, $format, $input, $output, $io);
        }

        $failed = array_filter($report->insights, static fn ($i): bool => !$i->succeeded());
        if ($failed !== [] && count($failed) === count($report->insights)) {
            $io->error('No AI task succeeded; see the messages above.');

            return self::FAILURE;
        }

        return $this->exitCode($report, $failOn !== null ? Severity::fromName($failOn) : null);
    }

    private function dryRun(InspectionReport $report, InputInterface $input, SymfonyStyle $io): void
    {
        $redactor = $this->redactor ?? new SecretRedactor();
        $analyzer = new AIAnalyzer(new PromptBuilder($redactor, $this->services->aiConfig()->maxInputChars()), $redactor);
        $previews = $analyzer->preview($report, TaskCatalog::select(self::listOption($input, 'ai-task')));

        $io->title('AI dry run: nothing will be sent');
        foreach ($previews as $preview) {
            $request = $preview['request'];
            $io->section(sprintf('%s (%s characters)', $preview['task']->key, number_format($request->characterCount())));
            foreach ($request->messages as $message) {
                $io->writeln(sprintf('<info>[%s]</info>', $message->role));
                $io->writeln($message->content, OutputInterface::OUTPUT_RAW);
                $io->newLine();
            }
            foreach ($preview['notes'] as $note) {
                $io->note($note);
            }
        }
    }
}
