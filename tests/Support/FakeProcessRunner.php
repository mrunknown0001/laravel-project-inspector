<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Support;

use LaraInspect\Model\AuditEntry;
use LaraInspect\Process\AuditLog;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Process\ProcessRunnerInterface;

/**
 * Scripted process runner. Responses are keyed by "<executable> <first argument>".
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<CommandSpec> */
    public array $commands = [];

    /** @var array<string, ProcessResult> */
    private array $responses = [];

    /**
     * @param list<string> $available
     */
    public function __construct(
        private array $available = [],
        private readonly ?AuditLog $audit = null,
    ) {
    }

    public function respond(string $key, ProcessResult $result): self
    {
        $this->responses[$key] = $result;
        $executable = explode(' ', $key)[0];
        if (!in_array($executable, $this->available, true)) {
            $this->available[] = $executable;
        }

        return $this;
    }

    public function isAvailable(string $executable): bool
    {
        return in_array($executable, $this->available, true);
    }

    public function run(CommandSpec $command): ProcessResult
    {
        $this->commands[] = $command;
        $key = $command->executable . ' ' . ($command->arguments[0] ?? '');
        $result = $this->responses[$key] ?? ProcessResult::notFound($command->executable);

        $this->audit?->record(new AuditEntry(
            '2026-11-01T00:00:00+00:00',
            $command->purpose,
            $command->commandLine(),
            $command->workingDirectory ?? '',
            $result->outcome,
            $result->exitCode,
            $result->durationMs,
            $command->network,
        ));

        return $result;
    }

    /**
     * @return list<string>
     */
    public function commandLines(): array
    {
        return array_map(static fn (CommandSpec $c): string => $c->commandLine(), $this->commands);
    }
}
