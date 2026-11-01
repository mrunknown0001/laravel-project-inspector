<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Core;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\AnalyzerRegistry;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;
use PHPUnit\Framework\TestCase;

final class AnalyzerRegistryTest extends TestCase
{
    public static function analyzer(string $name, ?\Closure $analyze = null): AnalyzerInterface
    {
        return new class ($name, $analyze) implements AnalyzerInterface {
            public function __construct(private readonly string $name, private readonly ?\Closure $analyze)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function title(): string
            {
                return ucfirst($this->name);
            }

            public function analyze(InspectionContext $context): AnalyzerResult
            {
                if ($this->analyze !== null) {
                    $result = ($this->analyze)($context);
                    if ($result instanceof AnalyzerResult) {
                        return $result;
                    }
                }

                return new AnalyzerResult($this->name, $this->title());
            }
        };
    }

    private function registry(): AnalyzerRegistry
    {
        return new AnalyzerRegistry([self::analyzer('environment'), self::analyzer('dependencies'), self::analyzer('routes')]);
    }

    /**
     * @param list<AnalyzerInterface> $analyzers
     * @return list<string>
     */
    private static function names(array $analyzers): array
    {
        return array_map(static fn (AnalyzerInterface $a): string => $a->name(), $analyzers);
    }

    public function testSelectsAllByDefaultInRegistrationOrder(): void
    {
        self::assertSame(['environment', 'dependencies', 'routes'], self::names($this->registry()->select()));
    }

    public function testOnlyAndExcludeFilterAnalyzers(): void
    {
        $registry = $this->registry();

        self::assertSame(['environment', 'routes'], self::names($registry->select(['routes', 'environment'])));
        self::assertSame(['environment', 'routes'], self::names($registry->select([], ['dependencies'])));
        self::assertSame(['routes'], self::names($registry->select(['routes', 'environment'], ['environment'])));
    }

    public function testUnknownNamesAreRejectedWithTheAvailableList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown analyzer: secrity. Available: environment, dependencies, routes.');
        $this->registry()->select(['secrity']);
    }

    public function testEmptySelectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No analyzers left');
        $this->registry()->select(['routes'], ['routes']);
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        new AnalyzerRegistry([self::analyzer('routes'), self::analyzer('routes')]);
    }
}
