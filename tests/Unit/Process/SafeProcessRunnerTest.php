<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Process;

use LaraInspect\Process\AuditLog;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ConfirmationInterface;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Process\SafeProcessRunner;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class SafeProcessRunnerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private AuditLog $audit;

    protected function setUp(): void
    {
        $this->audit = new AuditLog(new SecretRedactor());
    }

    private function runner(bool $offline = false, ?ConfirmationInterface $confirmation = null, int $timeout = 10): SafeProcessRunner
    {
        return $confirmation === null
            ? new SafeProcessRunner($this->audit, $timeout, offline: $offline)
            : new SafeProcessRunner($this->audit, $timeout, $confirmation, $offline);
    }

    private static function php(string $code, bool $network = false, bool $sideEffects = false, ?int $timeout = null): CommandSpec
    {
        return new CommandSpec('php', ['-r', $code], 'test', sys_get_temp_dir(), $timeout, $network, $sideEffects);
    }

    public function testRunsAllowlistedCommandsWithoutAShell(): void
    {
        // If a shell were involved, "; echo injected" would run as a second command.
        $result = $this->runner()->run(new CommandSpec('php', ['-r', 'echo $argv[1];', '--', 'safe; echo injected'], 'test'));

        self::assertTrue($result->isSuccessful());
        self::assertSame('safe; echo injected', $result->output);
    }

    public function testCapturesExitCodesAndErrorOutput(): void
    {
        $result = $this->runner()->run(self::php('fwrite(STDERR, "bad"); exit(3);'));

        self::assertTrue($result->ran());
        self::assertFalse($result->isSuccessful());
        self::assertSame(3, $result->exitCode);
        self::assertSame('bad', $result->errorOutput);
        self::assertSame('the command exited with code 3', $result->failureReason());
    }

    public function testEnforcesTimeouts(): void
    {
        $started = microtime(true);
        $result = $this->runner()->run(self::php('sleep(10);', timeout: 1));

        self::assertSame(ProcessResult::OUTCOME_TIMED_OUT, $result->outcome);
        self::assertLessThan(5, microtime(true) - $started);
    }

    public function testRefusesExecutablesOutsideTheAllowlist(): void
    {
        $result = $this->runner()->run(new CommandSpec('rm', ['-rf', '/tmp/should-never-run'], 'test'));

        self::assertSame(ProcessResult::OUTCOME_REFUSED, $result->outcome);
        self::assertStringContainsString('allowlist', $result->errorOutput);
        self::assertFalse($this->runner()->isAvailable('rm'));
    }

    public function testSideEffectsNeedConfirmation(): void
    {
        $marker = $this->tempDir() . '/created';
        $command = self::php(sprintf('touch(%s);', var_export($marker, true)), sideEffects: true);

        $refused = $this->runner()->run($command);
        self::assertSame(ProcessResult::OUTCOME_REFUSED, $refused->outcome);
        self::assertFileDoesNotExist($marker);

        $asked = [];
        $approve = new class ($asked) implements ConfirmationInterface {
            /** @param list<string> $asked */
            public function __construct(public array &$asked)
            {
            }

            public function confirm(CommandSpec $command): bool
            {
                $this->asked[] = $command->purpose;

                return true;
            }
        };
        self::assertTrue($this->runner(confirmation: $approve)->run($command)->isSuccessful());
        self::assertFileExists($marker);
        self::assertSame(['test'], $asked);
    }

    public function testOfflineModeRefusesNetworkCommands(): void
    {
        $result = $this->runner(offline: true)->run(self::php('echo 1;', network: true));

        self::assertSame(ProcessResult::OUTCOME_REFUSED, $result->outcome);
        self::assertStringContainsString('offline', $result->failureReason());
    }

    public function testMissingExecutablesAreReported(): void
    {
        $runner = new SafeProcessRunner($this->audit, allowlist: ['larainspect-missing-binary-xyz']);

        self::assertFalse($runner->isAvailable('larainspect-missing-binary-xyz'));
        self::assertSame(ProcessResult::OUTCOME_NOT_FOUND, $runner->run(new CommandSpec('larainspect-missing-binary-xyz', [], 'test'))->outcome);
    }

    public function testEveryRunIsAuditedWithSecretsRedacted(): void
    {
        $file = $this->tempDir() . '/audit.log';
        $this->audit->setFile($file);
        $runner = $this->runner();

        $runner->run(self::php('echo "DB_PASSWORD=hunter22-secret";'));
        $runner->run(new CommandSpec('rm', ['-rf', '/'], 'refused'));

        $entries = $this->audit->entries();
        self::assertCount(2, $entries);
        self::assertSame(ProcessResult::OUTCOME_COMPLETED, $entries[0]->outcome);
        self::assertStringNotContainsString('hunter22-secret', $entries[0]->command);
        self::assertSame(ProcessResult::OUTCOME_REFUSED, $entries[1]->outcome);

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(2, $lines);
        self::assertStringNotContainsString('hunter22-secret', (string) file_get_contents($file));
        self::assertSame('refused', json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR)['purpose']);
    }
}
