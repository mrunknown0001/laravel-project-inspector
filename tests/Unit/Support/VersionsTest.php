<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Support;

use LaraInspect\Support\LaravelSupportPolicy;
use LaraInspect\Support\Versions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionsTest extends TestCase
{
    public function testCleanAndMajor(): void
    {
        self::assertSame('10.48.4', Versions::clean('v10.48.4'));
        self::assertSame('dev-main', Versions::clean('dev-main'));
        self::assertSame(10, Versions::major('v10.48.4'));
        self::assertNull(Versions::major('dev-main'));
    }

    public function testConstraintLowestMajor(): void
    {
        self::assertSame(10, Versions::constraintLowestMajor('^10.10'));
        self::assertSame(8, Versions::constraintLowestMajor('^8.75|^9.0'));
        self::assertSame(11, Versions::constraintLowestMajor('>=11.0 <12'));
        self::assertNull(Versions::constraintLowestMajor('*'));
        self::assertNull(Versions::constraintLowestMajor('not a constraint'));
    }

    public function testConstraintChecksReturnNullOnGarbage(): void
    {
        self::assertTrue(Versions::satisfies('v8.83.27', '^8.75'));
        self::assertFalse(Versions::satisfies('v2.7.0', '^2.8'));
        self::assertNull(Versions::satisfies('dev-main', '^1.0'));
        self::assertNull(Versions::satisfies('1.0.0', '!!!'));

        self::assertTrue(Versions::intersects('^8.2', '>=8.4'));
        self::assertTrue(Versions::intersects('^8.1', '>=8.2'));
        self::assertFalse(Versions::intersects('~8.1.0', '>=8.2'));
        self::assertTrue(Versions::isSubsetOf('^8.2', '>=8.1'));
        self::assertFalse(Versions::isSubsetOf('^8.1', '>=8.2'));
        self::assertNull(Versions::isSubsetOf('^8.1', 'garbage'));
    }

    public function testExtractsVersionsFromToolOutput(): void
    {
        self::assertSame('2.10.3', Versions::extract("Composer version 2.10.3 2026-08-27 13:34:23\nPHP version 8.3.32"));
        self::assertSame('22.23.3', Versions::extract('v22.23.3'));
        self::assertSame('10.9.9', Versions::extract("10.9.9\n"));
        self::assertNull(Versions::extract('command not found'));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function supportStatuses(): iterable
    {
        yield 'Laravel 7 is end of life' => [7, '2026-11-01', LaravelSupportPolicy::STATUS_END_OF_LIFE];
        yield 'Laravel 8 is end of life' => [8, '2026-11-01', LaravelSupportPolicy::STATUS_END_OF_LIFE];
        yield 'Laravel 11 last security day' => [11, '2026-03-12', LaravelSupportPolicy::STATUS_SECURITY_ONLY];
        yield 'Laravel 11 after security end' => [11, '2026-03-13', LaravelSupportPolicy::STATUS_END_OF_LIFE];
        yield 'Laravel 12 security only' => [12, '2026-11-01', LaravelSupportPolicy::STATUS_SECURITY_ONLY];
        yield 'Laravel 12 active before bug-fix end' => [12, '2026-08-13', LaravelSupportPolicy::STATUS_ACTIVE];
        yield 'Laravel 13 active' => [13, '2026-11-01', LaravelSupportPolicy::STATUS_ACTIVE];
        yield 'Laravel 14 unknown' => [14, '2026-11-01', LaravelSupportPolicy::STATUS_UNKNOWN];
    }

    #[DataProvider('supportStatuses')]
    public function testLaravelSupportStatus(int $major, string $date, string $expected): void
    {
        self::assertSame($expected, LaravelSupportPolicy::status($major, new \DateTimeImmutable($date)));
    }
}
