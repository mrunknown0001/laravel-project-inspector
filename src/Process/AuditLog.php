<?php

declare(strict_types=1);

namespace LaraInspect\Process;

use LaraInspect\Model\AuditEntry;
use LaraInspect\Security\SecretRedactor;

/**
 * Records every external operation. Entries are kept in memory for the report
 * and, when a file is configured, appended to it as JSON lines.
 */
final class AuditLog
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
        private ?string $file = null,
    ) {
    }

    public function setFile(?string $file): void
    {
        $this->file = $file;
    }

    public function record(AuditEntry $entry): void
    {
        $entry = new AuditEntry(
            $entry->timestamp,
            $entry->purpose,
            $this->redactor->redact($entry->command),
            $entry->workingDirectory,
            $entry->outcome,
            $entry->exitCode,
            $entry->durationMs,
            $entry->network,
        );
        $this->entries[] = $entry;

        if ($this->file !== null) {
            $line = json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line !== false) {
                @file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
