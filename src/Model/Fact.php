<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Support\Arr;

/**
 * A single labelled piece of information, e.g. "Laravel version: 11.34.2".
 */
final readonly class Fact
{
    /**
     * @param string|null $origin Where the value was read from, e.g. "composer.lock".
     */
    public function __construct(
        public string $label,
        public string $value,
        public Source $source = Source::Detected,
        public ?string $origin = null,
    ) {
    }

    public static function unknown(string $label, string $reason = 'Not determined'): self
    {
        return new self($label, $reason, Source::Unknown);
    }

    /**
     * @return array{label: string, value: string, source: string, origin: string|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'source' => $this->source->value,
            'origin' => $this->origin,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $origin = Arr::string($data, 'origin');

        return new self(
            Arr::string($data, 'label'),
            Arr::string($data, 'value'),
            Source::tryFrom(Arr::string($data, 'source')) ?? Source::Unknown,
            $origin === '' ? null : $origin,
        );
    }
}
