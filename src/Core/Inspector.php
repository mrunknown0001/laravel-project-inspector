<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Finding;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Process\AuditLog;

/**
 * Runs analyzers and assembles a redacted report.
 *
 * A failing analyzer is recorded as failed; it never aborts the whole inspection.
 */
final class Inspector
{
    public function __construct(
        private readonly string $version,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param list<AnalyzerInterface>               $analyzers
     * @param (callable(AnalyzerInterface): void)|null $onStart Progress callback.
     */
    public function inspect(InspectionContext $context, array $analyzers, ?callable $onStart = null): InspectionReport
    {
        $started = hrtime(true);
        $results = [];

        // Loading .env registers its secret values with the redactor, even when
        // the selected analyzers never read it themselves.
        $context->env();

        $seen = [];
        foreach ($analyzers as $analyzer) {
            if ($onStart !== null) {
                $onStart($analyzer);
            }
            $results[] = $this->withoutDuplicates($this->runAnalyzer($analyzer, $context), $seen);
        }

        $report = new InspectionReport(
            inspectorVersion: $this->version,
            generatedAt: $context->options->now,
            project: $context->project,
            results: $results,
            audit: $this->audit->entries(),
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            offline: $context->options->offline,
        );

        // Redact everything once, at the boundary, so no reporter can leak a secret.
        return InspectionReport::fromArray($context->redactor->redactArray($report->toArray()));
    }

    /**
     * Analyzers may share rules (e.g. security advisories appear under both
     * dependencies and security). A finding is kept only where it first appears.
     *
     * @param array<string, true> $seen
     */
    private function withoutDuplicates(AnalyzerResult $result, array &$seen): AnalyzerResult
    {
        $findings = [];
        foreach ($result->findings as $finding) {
            $key = $finding->id . '|' . $finding->component . '|' . $finding->title;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $findings[] = $finding;
            }
        }

        return new AnalyzerResult($result->analyzer, $result->title, $result->status, $result->facts, $result->tables, $findings, $result->notes, $result->data);
    }

    private function runAnalyzer(AnalyzerInterface $analyzer, InspectionContext $context): AnalyzerResult
    {
        try {
            $result = $analyzer->analyze($context);
        } catch (\Throwable $e) {
            return AnalyzerResult::failed(
                $analyzer->name(),
                $analyzer->title(),
                sprintf('Analyzer failed: %s', $e->getMessage()),
            );
        }

        return new AnalyzerResult(
            $result->analyzer,
            $result->title,
            $result->status,
            $result->facts,
            $result->tables,
            array_map(static fn (Finding $f): Finding => $f->withAnalyzer($analyzer->name()), $result->findings),
            $result->notes,
            $result->data,
        );
    }
}
