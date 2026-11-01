<?php

declare(strict_types=1);

namespace LaraInspect\Console;

use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ConfirmationInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks before running a command with side effects. Defaults to "no", and
 * refuses outright when the session is not interactive (CI, --no-interaction).
 */
final class ConsoleConfirmation implements ConfirmationInterface
{
    public function __construct(
        private readonly InputInterface $input,
        private readonly SymfonyStyle $io,
    ) {
    }

    public function confirm(CommandSpec $command): bool
    {
        if (!$this->input->isInteractive()) {
            $this->io->warning(sprintf('Skipped "%s": it needs interactive confirmation.', $command->commandLine()));

            return false;
        }

        $this->io->section('Confirmation required');
        $this->io->text([
            sprintf('<info>Purpose:</info> %s', $command->purpose),
            sprintf('<info>Command:</info> %s', $command->commandLine()),
            sprintf('<info>Directory:</info> %s', $command->workingDirectory ?? (string) getcwd()),
        ]);
        if ($command->warning !== null) {
            $this->io->warning($command->warning);
        }

        return $this->io->confirm('Run this command?', false);
    }
}
