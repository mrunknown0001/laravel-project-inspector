<?php

declare(strict_types=1);

namespace LaraInspect\Process;

/**
 * Default policy: commands with side effects are never run.
 */
final class DenyAllConfirmation implements ConfirmationInterface
{
    public function confirm(CommandSpec $command): bool
    {
        return false;
    }
}
