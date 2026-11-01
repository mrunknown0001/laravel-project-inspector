<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Parsing;

use LaraInspect\Parsing\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    public function testOnlyAllowlistedValuesAreReadable(): void
    {
        $env = EnvFile::fromContents("APP_ENV=production\nAPP_DEBUG=true\nDB_PASSWORD=hunter22-pass\nAPI_TOKEN=\n", '.env');

        self::assertSame('production', $env->get('APP_ENV'));
        self::assertTrue($env->typed('APP_DEBUG'));
        self::assertNull($env->get('DB_PASSWORD'));
        self::assertTrue($env->has('DB_PASSWORD'));
        self::assertTrue($env->isFilled('DB_PASSWORD'));
        self::assertFalse($env->isReadable('DB_PASSWORD'));
        self::assertTrue($env->has('API_TOKEN'));
        self::assertFalse($env->isFilled('API_TOKEN'));
        self::assertFalse($env->has('MISSING'));
        self::assertSame(['APP_ENV', 'APP_DEBUG', 'DB_PASSWORD', 'API_TOKEN'], $env->keys());
    }

    public function testSensitiveValuesAreOnlyExposedForRedaction(): void
    {
        $env = EnvFile::fromContents("APP_ENV=local\nDB_PASSWORD=hunter22-pass\n", '.env');

        self::assertSame(['hunter22-pass'], $env->secretValuesForRedaction());
        self::assertStringNotContainsString('hunter22', serialize([$env->keys(), $env->get('DB_PASSWORD')]));
    }

    public function testTracksWhetherValuesComeFromTheExampleFile(): void
    {
        self::assertFalse(EnvFile::fromContents('', '.env')->isExample());
        self::assertTrue(EnvFile::fromContents('', '.env.example')->isExample());
        self::assertFalse(EnvFile::empty()->exists());
    }

    public function testCastsValuesLikeLaravel(): void
    {
        self::assertTrue(EnvFile::castLikeLaravel('(true)'));
        self::assertFalse(EnvFile::castLikeLaravel('FALSE'));
        self::assertNull(EnvFile::castLikeLaravel('null'));
        self::assertSame('', EnvFile::castLikeLaravel('(empty)'));
        self::assertSame('redis', EnvFile::castLikeLaravel('redis'));
    }
}
