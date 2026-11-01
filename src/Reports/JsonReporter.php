<?php

declare(strict_types=1);

namespace LaraInspect\Reports;

use LaraInspect\Contracts\ReporterInterface;
use LaraInspect\Model\InspectionReport;

/**
 * Machine-readable report. The same document can be loaded back by "larainspect report --from".
 */
final class JsonReporter implements ReporterInterface
{
    public function format(): string
    {
        return 'json';
    }

    public function fileExtension(): string
    {
        return 'json';
    }

    public function render(InspectionReport $report): string
    {
        return json_encode(
            $report->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
