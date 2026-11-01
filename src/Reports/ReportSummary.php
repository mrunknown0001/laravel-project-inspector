<?php

declare(strict_types=1);

namespace LaraInspect\Reports;

use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\ResultStatus;
use LaraInspect\Model\Severity;

/**
 * Presentation helpers shared by every reporter, so wording stays consistent.
 */
final class ReportSummary
{
    /**
     * One-sentence overall verdict.
     */
    public static function verdict(InspectionReport $report): string
    {
        $counts = $report->severityCounts();
        $total = array_sum($counts);
        $failed = count(array_filter($report->results, static fn ($r): bool => $r->status === ResultStatus::Failed));

        $sentence = match (true) {
            $total === 0 => 'No findings were reported by the selected analyzers.',
            $counts[Severity::Critical->value] > 0 => sprintf('%d finding%s, including %d critical: immediate attention recommended.', $total, $total === 1 ? '' : 's', $counts[Severity::Critical->value]),
            $counts[Severity::High->value] > 0 => sprintf('%d finding%s, including %d high severity: review soon.', $total, $total === 1 ? '' : 's', $counts[Severity::High->value]),
            $counts[Severity::Medium->value] > 0 => sprintf('%d finding%s; the most severe are medium.', $total, $total === 1 ? '' : 's'),
            default => sprintf('%d low-severity or informational finding%s.', $total, $total === 1 ? '' : 's'),
        };
        if ($failed > 0) {
            $sentence .= sprintf(' %d analyzer%s failed; see the notes in %s section.', $failed, $failed === 1 ? '' : 's', $failed === 1 ? 'its' : 'their');
        }

        return $sentence;
    }

    public static function framework(InspectionReport $report): string
    {
        $p = $report->project;

        return match (true) {
            $p->isLaravel && $p->laravelVersion !== null => 'Laravel ' . $p->laravelVersion,
            $p->isLaravel => 'Laravel (version unknown)',
            $p->framework === 'lumen' => 'Lumen (unsupported)',
            default => 'Not detected as a Laravel application',
        };
    }

    /**
     * @return list<array{0: string, 1: string}> Label/value pairs for the report header.
     */
    public static function metadata(InspectionReport $report): array
    {
        return [
            ['Project', $report->project->name],
            ['Path', $report->project->root],
            ['Framework', self::framework($report)],
            ['Generated', $report->generatedAt->format('Y-m-d H:i:s P')],
            ['Inspector version', $report->inspectorVersion],
            ['Duration', sprintf('%.1f s', $report->durationMs / 1000)],
            ['Network checks', $report->offline ? 'Disabled (offline)' : 'Enabled'],
        ];
    }

    public static function readOnlyStatement(): string
    {
        return 'The inspected project was not modified. Files were read without executing project code. External commands are listed under Operations.';
    }
}
