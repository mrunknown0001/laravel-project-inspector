<?php

declare(strict_types=1);

namespace LaraInspect\Contracts;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;

/**
 * One area of inspection. Implementations must be read-only with respect to the target project.
 */
interface AnalyzerInterface
{
    /**
     * Short machine name used by --only / --exclude, e.g. "dependencies".
     */
    public function name(): string;

    /**
     * Human-readable section title.
     */
    public function title(): string;

    public function analyze(InspectionContext $context): AnalyzerResult;
}
