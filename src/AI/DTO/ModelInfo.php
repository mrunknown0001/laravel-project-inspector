<?php

declare(strict_types=1);

namespace LaraInspect\AI\DTO;

final readonly class ModelInfo
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?int $contextWindow = null,
        public ?string $description = null,
    ) {
    }
}
