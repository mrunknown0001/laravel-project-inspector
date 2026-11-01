<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Core;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\Inspector;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\ResultStatus;
use LaraInspect\Model\Severity;
use LaraInspect\Process\AuditLog;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testAFailingAnalyzerDoesNotStopTheInspection(): void
    {
        $redactor = new SecretRedactor();
        $inspector = new Inspector('1.2.3', new AuditLog($redactor));
        $context = Fixtures::context(Fixtures::project('laravel-8'), redactor: $redactor);

        $report = $inspector->inspect($context, [
            AnalyzerRegistryTest::analyzer('broken', static fn () => throw new \RuntimeException('boom')),
            AnalyzerRegistryTest::analyzer('working'),
        ]);

        self::assertCount(2, $report->results);
        self::assertSame(ResultStatus::Failed, $report->results[0]->status);
        self::assertStringContainsString('boom', $report->results[0]->notes[0]);
        self::assertSame(ResultStatus::Completed, $report->results[1]->status);
        self::assertSame('1.2.3', $report->inspectorVersion);
        self::assertSame(Fixtures::NOW, $report->generatedAt->format(\DateTimeInterface::ATOM));
    }

    public function testFindingsAreTaggedWithTheirAnalyzer(): void
    {
        $redactor = new SecretRedactor();
        $report = (new Inspector('1.0.0', new AuditLog($redactor)))->inspect(
            Fixtures::context(Fixtures::project('laravel-8'), redactor: $redactor),
            [AnalyzerRegistryTest::analyzer('sample', static fn () => new AnalyzerResult('sample', 'Sample', findings: [
                new Finding('x.rule', 'Title', Severity::Low, Confidence::Potential, 'file.php', 'why', 'evidence', 'fix'),
            ]))],
        );

        self::assertSame('sample', $report->findings()[0]->analyzer);
    }

    public function testDuplicateFindingsAreKeptOnlyWhereTheyFirstAppear(): void
    {
        $finding = new Finding('deps.security-advisory', 'x/y has 1 advisory', Severity::High, Confidence::Confirmed, 'composer.lock: x/y', 'e', 'v', 'r');
        $make = static fn (string $name) => AnalyzerRegistryTest::analyzer($name, static fn () => new AnalyzerResult($name, ucfirst($name), findings: [
            $finding,
            new Finding('own.' . $name, 'Own', Severity::Low, Confidence::Potential, 'c', 'e', 'v', 'r'),
        ]));
        $redactor = new SecretRedactor();

        $report = (new Inspector('1.0.0', new AuditLog($redactor)))->inspect(Fixtures::context(Fixtures::project('laravel-8'), redactor: $redactor), [$make('dependencies'), $make('security')]);

        self::assertSame(['deps.security-advisory', 'own.dependencies'], array_map(static fn ($f) => $f->id, $report->results[0]->findings));
        self::assertSame(['own.security'], array_map(static fn ($f) => $f->id, $report->results[1]->findings));
    }

    public function testEverythingIsRedactedIncludingValuesOnlyKnownFromDotEnv(): void
    {
        // The laravel-8 fixture's .env holds DB_PASSWORD="Fixture-DB-Pass-8812".
        $redactor = new SecretRedactor();
        $leaky = AnalyzerRegistryTest::analyzer('leaky', static fn (InspectionContext $c) => new AnalyzerResult(
            'leaky',
            'Leaky',
            facts: [new Fact('Echo', 'connection uses Fixture-DB-Pass-8812')],
            findings: [new Finding('x', 'Leak', Severity::High, Confidence::Confirmed, 'a', 'b', "'password' => 'inline-secret-1'", 'c')],
            data: ['nested' => ['token' => 'Fixture-DB-Pass-8812']],
        ));

        $report = (new Inspector('1.0.0', new AuditLog($redactor)))->inspect(
            Fixtures::context(Fixtures::project('laravel-8'), redactor: $redactor),
            [$leaky],
        );
        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Fixture-DB-Pass-8812', $json);
        self::assertStringNotContainsString('inline-secret-1', $json);
        self::assertStringContainsString('[REDACTED]', $json);
    }
}
