<?php

declare(strict_types=1);

namespace LaraInspect\Reports;

use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\ResultStatus;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Terminal output. Long tables are truncated unless -v is given.
 */
final class ConsoleReporter
{
    private const ROW_LIMIT = 15;

    public function render(InspectionReport $report, SymfonyStyle $io): void
    {
        $verbose = $io->isVerbose();

        $io->title('Laravel Project Inspector ' . $report->inspectorVersion);
        $io->definitionList(...array_map(
            static fn (array $pair): array => [$pair[0] => OutputFormatter::escape($pair[1])],
            ReportSummary::metadata($report),
        ));
        foreach ($report->project->warnings as $warning) {
            $io->warning($warning);
        }

        foreach ($report->results as $result) {
            $this->section($result, $io, $verbose);
        }

        $findings = $report->findings();
        if ($findings !== []) {
            $io->section('Findings');
            foreach ($findings as $i => $finding) {
                $this->finding($finding, $i + 1, $io, $verbose);
            }
            if (!$verbose) {
                $io->text('<comment>Run with -v to see evidence and remediation for every finding.</comment>');
                $io->newLine();
            }
        }

        if ($report->insights !== []) {
            $this->insights($report, $io);
        }

        if ($verbose && $report->audit !== []) {
            $io->section('Operations');
            $io->table(
                ['Purpose', 'Command', 'Outcome', 'ms'],
                array_map(static fn ($e): array => [
                    OutputFormatter::escape($e->purpose),
                    OutputFormatter::escape($e->command),
                    $e->outcome . ($e->exitCode !== null ? ' (' . $e->exitCode . ')' : ''),
                    (string) $e->durationMs,
                ], $report->audit),
            );
        }

        $io->section('Summary');
        $counts = [];
        foreach ($report->severityCounts() as $severity => $count) {
            $counts[] = sprintf('%s %d', self::badge(Severity::from($severity)), $count);
        }
        $io->text(implode('   ', $counts));
        $io->newLine();

        $verdict = ReportSummary::verdict($report);
        $highest = $report->highestSeverity();
        match (true) {
            $highest === null => $io->success($verdict),
            $highest->isAtLeast(Severity::High) => $io->error($verdict),
            $highest === Severity::Medium => $io->warning($verdict),
            default => $io->note($verdict),
        };
    }

    public function insights(InspectionReport $report, SymfonyStyle $io): void
    {
        $io->section('AI-assisted analysis (AI-generated)');
        $io->text('<comment>Written by a language model from the redacted inspection results. It can be wrong and does not replace the findings above.</comment>');
        $io->newLine();
        foreach ($report->insights as $insight) {
            $io->writeln(sprintf('<info>%s</info> <fg=gray>(%s · %s · %s)</>', OutputFormatter::escape($insight->title), OutputFormatter::escape($insight->provider), OutputFormatter::escape($insight->model), OutputFormatter::escape($insight->usage->describe())));
            if ($insight->error !== null) {
                $io->writeln('<error> Not available </error> ' . OutputFormatter::escape($insight->error));
            }
            if ($insight->content !== '') {
                $io->writeln(OutputFormatter::escape($insight->content));
            }
            foreach ($insight->notes as $note) {
                $io->writeln('<fg=gray>Note:</> ' . OutputFormatter::escape($note));
            }
            $io->newLine();
        }
    }

    private function section(AnalyzerResult $result, SymfonyStyle $io, bool $verbose): void
    {
        $io->section($result->title . ($result->status !== ResultStatus::Completed ? ' [' . $result->status->value . ']' : ''));

        if ($result->facts !== []) {
            $io->table(['Item', 'Value', 'Source'], array_map(static fn ($fact): array => [
                OutputFormatter::escape($fact->label),
                OutputFormatter::escape($fact->value),
                match ($fact->source) {
                    Source::Detected => 'detected',
                    Source::Inferred => '<comment>inferred</comment>',
                    Source::Unknown => '<fg=gray>unknown</>',
                } . ($verbose && $fact->origin !== null ? ' <fg=gray>' . OutputFormatter::escape($fact->origin) . '</>' : ''),
            ], $result->facts));
        }

        foreach ($result->tables as $table) {
            if ($table->isEmpty()) {
                continue;
            }
            $rows = $verbose ? $table->rows : array_slice($table->rows, 0, self::ROW_LIMIT);
            $io->text('<info>' . OutputFormatter::escape($table->title) . '</info>');
            $io->table(
                array_map(OutputFormatter::escape(...), $table->headers),
                array_map(static fn (array $row): array => array_map(OutputFormatter::escape(...), $row), $rows),
            );
            if (count($rows) < count($table->rows)) {
                $io->text(sprintf('<fg=gray>... %d more rows (use -v to show all)</>', count($table->rows) - count($rows)));
                $io->newLine();
            }
        }

        foreach ($result->notes as $note) {
            $io->text('<fg=gray>Note:</> ' . OutputFormatter::escape($note));
        }
        if ($result->notes !== []) {
            $io->newLine();
        }
    }

    private function finding(Finding $finding, int $number, SymfonyStyle $io, bool $verbose): void
    {
        $io->writeln(sprintf(
            ' %s %d. %s%s',
            self::badge($finding->severity),
            $number,
            OutputFormatter::escape($finding->title),
            $finding->confidence === Confidence::Potential ? ' <fg=gray>(potential)</>' : '',
        ));
        $io->writeln('    <fg=gray>' . OutputFormatter::escape($finding->component) . '</>');

        if ($verbose) {
            $io->writeln('    ' . OutputFormatter::escape($finding->explanation));
            if ($finding->evidence !== '') {
                foreach (explode("\n", $finding->evidence) as $line) {
                    $io->writeln('    <fg=gray>│</> ' . OutputFormatter::escape($line));
                }
            }
            $io->writeln('    <info>Fix:</info> ' . OutputFormatter::escape($finding->remediation));
            $io->newLine();
        }
    }

    public static function badge(Severity $severity): string
    {
        $style = match ($severity) {
            Severity::Critical => 'fg=white;bg=magenta;options=bold',
            Severity::High => 'fg=white;bg=red;options=bold',
            Severity::Medium => 'fg=black;bg=yellow',
            Severity::Low => 'fg=white;bg=blue',
            Severity::Info => 'fg=black;bg=white',
        };

        return sprintf('<%s> %s </>', $style, $severity->value);
    }
}
