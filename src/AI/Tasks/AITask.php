<?php

declare(strict_types=1);

namespace LaraInspect\AI\Tasks;

/**
 * One AI-assisted feature: what to ask and which inspection results it needs.
 */
final readonly class AITask
{
    /**
     * @param list<string> $analyzers Analyzer results included in the prompt; empty = all.
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $instruction,
        public array $analyzers = [],
    ) {
    }
}
