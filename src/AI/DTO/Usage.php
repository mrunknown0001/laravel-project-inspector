<?php

declare(strict_types=1);

namespace LaraInspect\AI\DTO;

/**
 * Token usage as reported by the provider. Fields are null when not reported.
 */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?float $cost = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function total(): ?int
    {
        return $this->totalTokens ?? ($this->inputTokens !== null && $this->outputTokens !== null ? $this->inputTokens + $this->outputTokens : null);
    }

    public function add(self $other): self
    {
        $sum = static fn (?int $a, ?int $b): ?int => $a === null && $b === null ? null : (int) $a + (int) $b;

        return new self(
            $sum($this->inputTokens, $other->inputTokens),
            $sum($this->outputTokens, $other->outputTokens),
            $sum($this->total(), $other->total()),
            $this->cost === null && $other->cost === null ? null : (float) $this->cost + (float) $other->cost,
        );
    }

    public function describe(): string
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return 'not reported';
        }
        $text = sprintf('%s input / %s output tokens', $this->inputTokens ?? '?', $this->outputTokens ?? '?');

        return $this->cost !== null ? $text . sprintf(' (cost %s)', rtrim(rtrim(number_format($this->cost, 6, '.', ''), '0'), '.')) : $text;
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, cost: float|null}
     */
    public function toArray(): array
    {
        return ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'total_tokens' => $this->total(), 'cost' => $this->cost];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $k): ?int => isset($data[$k]) && is_numeric($data[$k]) ? (int) $data[$k] : null;

        return new self($int('input_tokens'), $int('output_tokens'), $int('total_tokens'), isset($data['cost']) && is_numeric($data['cost']) ? (float) $data['cost'] : null);
    }
}
