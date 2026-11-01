<?php

declare(strict_types=1);

namespace LaraInspect\Process;

/**
 * Asks the user before a command with side effects is run.
 */
interface ConfirmationInterface
{
    public function confirm(CommandSpec $command): bool;
}
