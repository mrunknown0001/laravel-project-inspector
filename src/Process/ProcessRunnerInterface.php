<?php

declare(strict_types=1);

namespace LaraInspect\Process;

interface ProcessRunnerInterface
{
    /**
     * Runs a command. Never throws for failures; inspect the result instead.
     */
    public function run(CommandSpec $command): ProcessResult;

    public function isAvailable(string $executable): bool;
}
