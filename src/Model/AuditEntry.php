<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Support\Arr;

/**
 * Record of one external operation performed during an inspection.
 */
final readonly class AuditEntry
{
    public function __construct(
        public string $timestamp,
        public string $purpose,
        public string $command,
        public string $workingDirectory,
        public string $outcome,
        public ?int $exitCode,
        public int $durationMs,
        public bool $network,
    ) {
    }

    /**
     * @return array{timestamp: string, purpose: string, command: string, working_directory: string, outcome: string, exit_code: int|null, duration_ms: int, network: bool}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'purpose' => $this->purpose,
            'command' => $this->command,
            'working_directory' => $this->workingDirectory,
            'outcome' => $this->outcome,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'network' => $this->network,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::string($data, 'timestamp'),
            Arr::string($data, 'purpose'),
            Arr::string($data, 'command'),
            Arr::string($data, 'working_directory'),
            Arr::string($data, 'outcome'),
            isset($data['exit_code']) && is_int($data['exit_code']) ? $data['exit_code'] : null,
            Arr::int($data, 'duration_ms'),
            Arr::bool($data, 'network'),
        );
    }
}
