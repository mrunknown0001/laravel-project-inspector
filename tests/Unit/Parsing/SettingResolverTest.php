<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Parsing;

use LaraInspect\Model\Source;
use LaraInspect\Parsing\SettingResolver;
use LaraInspect\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SettingResolverTest extends TestCase
{
    public function testPrefersTheConfigFileResolvedAgainstEnv(): void
    {
        $resolver = new SettingResolver(Fixtures::context(Fixtures::project('laravel-8')));

        $cache = $resolver->resolve('cache');
        self::assertSame('redis', $cache->value);
        self::assertSame(Source::Detected, $cache->source);

        $log = $resolver->resolve('log');
        self::assertSame('stack', $log->value);
        self::assertSame(Source::Inferred, $log->source);
    }

    public function testFallsBackToEnvThenFrameworkDefaultsForSlimSkeletons(): void
    {
        // Laravel 11 fixture ships only config/app.php and config/database.php.
        $resolver = new SettingResolver(Fixtures::context(Fixtures::project('laravel-11')));

        $cache = $resolver->resolve('cache');
        self::assertSame('database', $cache->value);
        self::assertSame('.env: CACHE_STORE', $cache->origin);

        $mail = $resolver->resolve('mail');
        self::assertSame('log', $mail->value);
        self::assertSame(Source::Inferred, $mail->source);
        self::assertStringContainsString('framework default', $mail->origin);
    }

    public function testValuesFromEnvExampleAreInferred(): void
    {
        $database = (new SettingResolver(Fixtures::context(Fixtures::project('laravel-10'))))->resolve('database');

        self::assertSame('pgsql', $database->value);
        self::assertSame(Source::Inferred, $database->source);
        self::assertSame('.env.example: DB_CONNECTION', $database->origin);
    }

    public function testDatabaseDriverLookup(): void
    {
        $resolver = new SettingResolver(Fixtures::context(Fixtures::project('laravel-8')));

        self::assertSame('mysql', $resolver->databaseDriver('mysql'));
        self::assertSame('sqlsrv', $resolver->databaseDriver('legacy'));
        self::assertNull($resolver->databaseDriver('custom-unknown'));
    }

    public function testUnknownSettingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SettingResolver(Fixtures::context(Fixtures::project('laravel-8'))))->resolve('nope');
    }
}
