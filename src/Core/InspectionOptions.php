<?php

declare(strict_types=1);

namespace LaraInspect\Core;

final readonly class InspectionOptions
{
    /**
     * @param bool         $useArtisan   Allow "php artisan route:list" (boots the project; asks first).
     * @param list<string> $qualityTools Code-quality tools to run (asks first); empty = detect only.
     */
    public function __construct(
        public bool $offline = false,
        public int $processTimeout = 60,
        public int $networkTimeout = 180,
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
        public bool $useArtisan = false,
        public array $qualityTools = [],
    ) {
    }
}
