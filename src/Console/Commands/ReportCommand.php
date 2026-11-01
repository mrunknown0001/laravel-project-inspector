<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\Exception\InspectorException;
use LaraInspect\Model\Severity;
use LaraInspect\Reports\ReportStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'report',
    description: 'Render a report from saved results, or from a fresh inspection when a path is given',
)]
final class ReportCommand extends AbstractInspectionCommand
{
    protected function defaultFormat(): string
    {
        return 'markdown';
    }

    protected function pathIsRequired(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Saved JSON results to render (default: the most recent file in the output directory)')
            ->setHelp(<<<'HELP'
                Renders a report.

                Without a path, the most recent saved results in ./reports are rendered:
                  <info>larainspect report --format=html</info>
                  <info>larainspect report --from=reports/myapp-20261101-120000.json --format=markdown</info>

                With a path, the project is inspected first (same options as "inspect"):
                  <info>larainspect report /path/to/project --format=markdown --output=docs/inspection.md</info>
                HELP);
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $path = $input->getArgument('path');
        if (is_string($path) && $path !== '') {
            if (self::stringOption($input, 'from') !== null) {
                throw new \InvalidArgumentException('Pass either a project path or --from, not both.');
            }

            return parent::handle($input, $output, $io);
        }

        $format = $this->services->reporters()->normalise(self::stringOption($input, 'format') ?? $this->defaultFormat());
        $failOn = self::stringOption($input, 'fail-on');

        $from = self::stringOption($input, 'from');
        if ($from === null) {
            $from = ReportStore::at($this->services->outputDirectory(), (string) getcwd())->latestJson()
                ?? throw new InspectorException('No saved results found in ./' . $this->services->outputDirectory() . '. Run "larainspect inspect <path>" first, or pass --from.');
        }

        $report = ReportStore::load($from);
        if ($output->isVerbose()) {
            $io->text(sprintf('<fg=gray>Rendering %s</>', $from));
        }
        if ((bool) $input->getOption('ai')) {
            $report = $this->runAi($report, $input, $output);
            $this->save($report, $input, $output);
        }
        $this->deliver($report, $format, $input, $output, $io);

        return $this->exitCode($report, $failOn !== null ? Severity::fromName($failOn) : null);
    }
}
