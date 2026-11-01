<?php

declare(strict_types=1);

namespace LaraInspect\Contracts;

use LaraInspect\Model\InspectionReport;

/**
 * Renders a report to a file format.
 */
interface ReporterInterface
{
    /**
     * Format name used by --format, e.g. "markdown".
     */
    public function format(): string;

    public function fileExtension(): string;

    public function render(InspectionReport $report): string;
}
