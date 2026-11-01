<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Model;

use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\Severity;
use LaraInspect\Tests\Support\SampleReport;
use PHPUnit\Framework\TestCase;

final class InspectionReportTest extends TestCase
{
    public function testFindingsAreSortedBySeverityKeepingAnalyzerOrder(): void
    {
        $titles = array_map(static fn ($f) => $f->severity->value, SampleReport::create()->findings());

        self::assertSame(['CRITICAL', 'HIGH', 'LOW'], $titles);
    }

    public function testSeverityCountsIncludeEveryLevel(): void
    {
        $report = SampleReport::create();

        self::assertSame(['CRITICAL' => 1, 'HIGH' => 1, 'MEDIUM' => 0, 'LOW' => 1, 'INFO' => 0], $report->severityCounts());
        self::assertSame(Severity::Critical, $report->highestSeverity());
        self::assertNotNull($report->result('dependencies'));
        self::assertNull($report->result('nope'));
    }

    public function testRoundTripsThroughArrays(): void
    {
        $report = SampleReport::create();

        self::assertEquals($report->toArray(), InspectionReport::fromArray($report->toArray())->toArray());
    }

    public function testRejectsUnknownSchemaVersions(): void
    {
        $data = SampleReport::create()->toArray();
        $data['schema_version'] = 99;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema version 99');
        InspectionReport::fromArray($data);
    }

    public function testSeverityParsing(): void
    {
        self::assertSame(Severity::High, Severity::fromName(' high '));
        self::assertSame(Severity::Critical, Severity::fromAdvisory('critical'));
        self::assertSame(Severity::Medium, Severity::fromAdvisory(null));
        self::assertTrue(Severity::High->isAtLeast(Severity::Medium));
        self::assertFalse(Severity::Low->isAtLeast(Severity::Medium));

        $this->expectException(\InvalidArgumentException::class);
        Severity::fromName('urgent');
    }
}
