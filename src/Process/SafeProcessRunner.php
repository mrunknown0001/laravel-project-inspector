<?php

declare(strict_types=1);

namespace LaraInspect\Process;

use LaraInspect\Model\AuditEntry;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessException;
use Symfony\Component\Process\Process;

/**
 * Runs allowlisted executables without a shell, with a timeout, and audits each run.
 *
 * Commands flagged with side effects are refused unless the confirmation
 * handler approves them. Commands that need the network are refused in
 * offline mode.
 */
final class SafeProcessRunner implements ProcessRunnerInterface
{
    public const DEFAULT_ALLOWLIST = ['composer', 'php', 'node', 'npm', 'pnpm', 'yarn', 'bun', 'git'];

    private readonly ExecutableFinder $finder;

    /** @var array<string, string|null> */
    private array $resolved = [];

    /**
     * @param list<string> $allowlist
     */
    public function __construct(
        private readonly AuditLog $audit,
        private readonly int $defaultTimeout = 60,
        private readonly ConfirmationInterface $confirmation = new DenyAllConfirmation(),
        private readonly bool $offline = false,
        private readonly array $allowlist = self::DEFAULT_ALLOWLIST,
    ) {
        $this->finder = new ExecutableFinder();
    }

    public function isAvailable(string $executable): bool
    {
        return in_array($executable, $this->allowlist, true) && $this->locate($executable) !== null;
    }

    public function run(CommandSpec $command): ProcessResult
    {
        $result = $this->execute($command);

        $this->audit->record(new AuditEntry(
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            $command->purpose,
            $command->commandLine(),
            $command->workingDirectory ?? (getcwd() ?: ''),
            $result->outcome,
            $result->exitCode,
            $result->durationMs,
            $command->network,
        ));

        return $result;
    }

    private function execute(CommandSpec $command): ProcessResult
    {
        if (!in_array($command->executable, $this->allowlist, true)) {
            return ProcessResult::refused(sprintf('"%s" is not on the executable allowlist', $command->executable));
        }
        if ($command->network && $this->offline) {
            return ProcessResult::refused('offline mode is enabled');
        }
        if ($command->sideEffects && !$this->confirmation->confirm($command)) {
            return ProcessResult::refused('the command may have side effects and was not confirmed');
        }

        $binary = $this->locate($command->executable);
        if ($binary === null) {
            return ProcessResult::notFound($command->executable);
        }

        $process = new Process(
            [$binary, ...$command->arguments],
            $command->workingDirectory,
            $command->env + ['COMPOSER_NO_INTERACTION' => '1', 'NO_COLOR' => '1'],
            null,
            (float) ($command->timeout ?? $this->defaultTimeout),
        );

        $started = hrtime(true);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                ProcessResult::OUTCOME_TIMED_OUT,
                null,
                $process->getOutput(),
                $process->getErrorOutput(),
                self::elapsedMs($started),
            );
        } catch (SymfonyProcessException $e) {
            return new ProcessResult(ProcessResult::OUTCOME_ERROR, null, '', $e->getMessage(), self::elapsedMs($started));
        }

        return ProcessResult::completed(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput(),
            self::elapsedMs($started),
        );
    }

    private function locate(string $executable): ?string
    {
        if (!array_key_exists($executable, $this->resolved)) {
            $this->resolved[$executable] = $executable === 'php' ? PHP_BINARY : $this->finder->find($executable);
        }

        return $this->resolved[$executable];
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
