<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Parsing;

use LaraInspect\Parsing\EnvFileParser;
use PHPUnit\Framework\TestCase;

final class EnvFileParserTest extends TestCase
{
    public function testParsesCommonDotenvSyntax(): void
    {
        $values = (new EnvFileParser())->parse(<<<'ENV'
            # comment
            APP_NAME=Laravel
            APP_ENV = production
            EMPTY=
            export EXPORTED=yes
            DOUBLE="double quoted # not a comment"
            SINGLE='single $NOT_EXPANDED'
            INLINE=value # trailing comment
            ESCAPED="line\nbreak \"quoted\""
            QUOTED_WITH_COMMENT="kept" # dropped
            MULTI="first
            second"
            AFTER=1
            not a valid line
            ENV);

        self::assertSame('Laravel', $values['APP_NAME']);
        self::assertSame('production', $values['APP_ENV']);
        self::assertSame('', $values['EMPTY']);
        self::assertSame('yes', $values['EXPORTED']);
        self::assertSame('double quoted # not a comment', $values['DOUBLE']);
        self::assertSame('single $NOT_EXPANDED', $values['SINGLE']);
        self::assertSame('value', $values['INLINE']);
        self::assertSame("line\nbreak \"quoted\"", $values['ESCAPED']);
        self::assertSame('kept', $values['QUOTED_WITH_COMMENT']);
        self::assertSame("first\nsecond", $values['MULTI']);
        self::assertSame('1', $values['AFTER']);
        self::assertCount(11, $values);
    }

    public function testNeverPerformsInterpolationOrCommandSubstitution(): void
    {
        $marker = sys_get_temp_dir() . '/larainspect-env-marker-' . bin2hex(random_bytes(4));
        $values = (new EnvFileParser())->parse("A=\$(touch {$marker})\nB=\${HOME}\nC=`touch {$marker}`\n");

        self::assertSame("\$(touch {$marker})", $values['A']);
        self::assertSame('${HOME}', $values['B']);
        self::assertSame("`touch {$marker}`", $values['C']);
        self::assertFileDoesNotExist($marker);
    }

    public function testHandlesBomAndWindowsLineEndings(): void
    {
        $values = (new EnvFileParser())->parse("\xEF\xBB\xBFAPP_ENV=local\r\nAPP_DEBUG=true\r\n");

        self::assertSame(['APP_ENV' => 'local', 'APP_DEBUG' => 'true'], $values);
    }

    public function testLastDefinitionWins(): void
    {
        self::assertSame(['A' => '2'], (new EnvFileParser())->parse("A=1\nA=2\n"));
    }

    public function testUnterminatedQuoteDoesNotLoopForever(): void
    {
        $values = (new EnvFileParser())->parse("A=\"never closed\nB=2\n");

        self::assertSame("never closed\nB=2\n", $values['A']);
    }
}
