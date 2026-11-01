<?php

declare(strict_types=1);

namespace LaraInspect\Core;

final readonly class InspectionOptions
{
    public function __construct(
        public bool $offline = false,
        public int $processTimeout = 60,
        public int $networkTimeout = 180,
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }
}
