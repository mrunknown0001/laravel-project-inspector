<?php

declare(strict_types=1);

namespace LaraInspect\Process;

/**
 * Description of an external command the inspector wants to run.
 */
final readonly class CommandSpec
{
    /**
     * @param string                $executable  Bare executable name, resolved on PATH (e.g. "composer").
     * @param list<string>          $arguments   Passed as an argv array; never through a shell.
     * @param string                $purpose     Human-readable reason, recorded in the audit log.
     * @param bool                  $network     Whether the command contacts remote services.
     * @param bool                  $sideEffects Whether it may change anything; requires explicit confirmation.
     * @param array<string, string> $env         Extra environment variables.
     * @param string|null           $warning     Shown when asking for confirmation: what could change.
     */
    public function __construct(
        public string $executable,
        public array $arguments,
        public string $purpose,
        public ?string $workingDirectory = null,
        public ?int $timeout = null,
        public bool $network = false,
        public bool $sideEffects = false,
        public array $env = [],
        public ?string $warning = null,
    ) {
    }

    public function commandLine(): string
    {
        $parts = [$this->executable];
        foreach ($this->arguments as $argument) {
            $parts[] = preg_match('/^[A-Za-z0-9_\-.,=\/:@%+]+$/', $argument) === 1 ? $argument : '"' . addcslashes($argument, '"\\') . '"';
        }

        return implode(' ', $parts);
    }
}
