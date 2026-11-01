<?php

declare(strict_types=1);

namespace LaraInspect\Composer;

final readonly class ComposerAuditResult
{
    /**
     * @param array<string, list<array<string, mixed>>> $advisories package => advisories
     * @param array<string, string|true>                $abandoned  package => replacement or true
     * @param list<array<string, mixed>>                $outdated   composer outdated entries with updates available
     * @param list<string>                              $notes
     */
    public function __construct(
        public bool $ran,
        public array $advisories = [],
        public array $abandoned = [],
        public array $outdated = [],
        public array $notes = [],
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self(false, notes: [$reason]);
    }
}
