<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

use LaraInspect\Model\Source;

final readonly class ConfigValue
{
    public function __construct(
        public string|int|float|bool|null $value,
        public Source $source,
        public string $origin,
        public bool $found = true,
    ) {
    }

    public static function missing(string $origin): self
    {
        return new self(null, Source::Unknown, $origin, false);
    }

    public static function unknown(string $origin): self
    {
        return new self(null, Source::Unknown, $origin);
    }

    public function isKnown(): bool
    {
        return $this->found && $this->source !== Source::Unknown;
    }

    public function asString(): ?string
    {
        if (!$this->isKnown()) {
            return null;
        }

        return self::format($this->value);
    }

    public function display(string $fallback = 'unknown'): string
    {
        return $this->asString() ?? $fallback;
    }

    public static function format(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}
