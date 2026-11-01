<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Console;

use LaraInspect\Console\ConsoleConfirmation;
use LaraInspect\Process\CommandSpec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConsoleConfirmationTest extends TestCase
{
    private static function command(): CommandSpec
    {
        return new CommandSpec('php', ['artisan', 'route:list'], 'List routes', '/srv/app', sideEffects: true, warning: 'This boots the application.');
    }

    private static function confirmation(string $answer, bool $interactive, BufferedOutput $output): ConsoleConfirmation
    {
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $answer . "\n");
        rewind($stream);
        $input->setStream($stream);

        return new ConsoleConfirmation($input, new SymfonyStyle($input, $output));
    }

    public function testNonInteractiveSessionsAreRefused(): void
    {
        $output = new BufferedOutput();

        self::assertFalse(self::confirmation('yes', false, $output)->confirm(self::command()));
        self::assertStringContainsString('needs interactive confirmation', $output->fetch());
    }

    public function testShowsTheCommandAndWarningAndDefaultsToNo(): void
    {
        $output = new BufferedOutput();
        self::assertFalse(self::confirmation('', true, $output)->confirm(self::command()));
        $text = $output->fetch();
        self::assertStringContainsString('php artisan route:list', $text);
        self::assertStringContainsString('This boots the application.', $text);

        self::assertTrue(self::confirmation('yes', true, new BufferedOutput())->confirm(self::command()));
    }
}
