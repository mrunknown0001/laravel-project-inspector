<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Support;

use LaraInspect\Core\ProjectInfo;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\AuditEntry;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;

/**
 * A hand-built report with hostile content, for reporter tests.
 */
final class SampleReport
{
    public static function create(string $root = '/srv/app'): InspectionReport
    {
        $project = new ProjectInfo($root, 'acme/<shop>', true, 'laravel', '11.34.2', 11, Source::Detected, 'composer.lock', ProjectInfo::LAYOUT_MODERN, ['artisan' => true], ['Example warning']);

        return new InspectionReport(
            '0.1.0',
            new \DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            $project,
            [
                new AnalyzerResult('environment', 'Environment', facts: [
                    new Fact('Laravel version', '11.34.2', Source::Detected, 'composer.lock'),
                    new Fact('Cache store', 'database', Source::Inferred, 'framework default'),
                ], tables: [
                    new Table('Drivers', ['Setting', 'Value'], [['cache', 'a|b'], ['queue', "multi\nline"]]),
                    new Table('Empty', ['A'], []),
                ], findings: [
                    new Finding('env.low', 'Low thing', Severity::Low, Confidence::Potential, 'composer.json', 'Explanation', 'evidence', 'Fix it', 'environment'),
                ], notes: ['A note']),
                new AnalyzerResult('dependencies', 'Dependencies', findings: [
                    new Finding('deps.xss', 'Title <script>alert(1)</script>', Severity::Critical, Confidence::Confirmed, 'composer.lock: x/y', 'Why `it` | matters', "line 1\n```\nline 3", 'Upgrade', 'dependencies'),
                    new Finding('deps.high', 'High thing', Severity::High, Confidence::Confirmed, 'composer.lock', 'Explanation', '', 'Fix', 'dependencies'),
                ]),
            ],
            [new AuditEntry('2026-11-01T12:00:01+00:00', 'Read Composer version', 'composer --version', '/tmp', 'completed', 0, 12, false)],
            1500,
            true,
        );
    }
}
