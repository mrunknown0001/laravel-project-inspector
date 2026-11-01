<?php

declare(strict_types=1);

namespace LaraInspect\Process;

final readonly class ProcessResult
{
    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_TIMED_OUT = 'timed-out';
    public const OUTCOME_NOT_FOUND = 'not-found';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_ERROR = 'error';

    public function __construct(
        public string $outcome,
        public ?int $exitCode = null,
        public string $output = '',
        public string $errorOutput = '',
        public int $durationMs = 0,
    ) {
    }

    public static function completed(int $exitCode, string $output, string $errorOutput = '', int $durationMs = 0): self
    {
        return new self(self::OUTCOME_COMPLETED, $exitCode, $output, $errorOutput, $durationMs);
    }

    public static function notFound(string $executable): self
    {
        return new self(self::OUTCOME_NOT_FOUND, errorOutput: sprintf('Executable "%s" was not found on PATH.', $executable));
    }

    public static function refused(string $reason): self
    {
        return new self(self::OUTCOME_REFUSED, errorOutput: $reason);
    }

    public function ran(): bool
    {
        return $this->outcome === self::OUTCOME_COMPLETED;
    }

    public function isSuccessful(): bool
    {
        return $this->ran() && $this->exitCode === 0;
    }

    /**
     * A short explanation suitable for a report note when the command did not succeed.
     */
    public function failureReason(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_TIMED_OUT => 'the command timed out',
            self::OUTCOME_NOT_FOUND => 'the executable was not found',
            self::OUTCOME_REFUSED => 'the command was not permitted (' . $this->errorOutput . ')',
            self::OUTCOME_ERROR => 'the command could not be started (' . $this->errorOutput . ')',
            default => sprintf('the command exited with code %d', $this->exitCode ?? -1),
        };
    }
}
