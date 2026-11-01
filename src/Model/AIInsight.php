<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\AI\DTO\Usage;
use LaraInspect\Support\Arr;

/**
 * AI-generated text for one task. Always rendered separately from, and
 * labelled differently than, the deterministic findings.
 */
final readonly class AIInsight
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public string $task,
        public string $title,
        public string $provider,
        public string $model,
        public string $content,
        public string $finishReason,
        public Usage $usage,
        public string $generatedAt,
        public ?string $error = null,
        public array $notes = [],
    ) {
    }

    public function succeeded(): bool
    {
        return $this->error === null && $this->content !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'title' => $this->title,
            'ai_generated' => true,
            'provider' => $this->provider,
            'model' => $this->model,
            'content' => $this->content,
            'finish_reason' => $this->finishReason,
            'usage' => $this->usage->toArray(),
            'generated_at' => $this->generatedAt,
            'error' => $this->error,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $usage */
        $usage = Arr::array($data, 'usage');
        $error = Arr::string($data, 'error');

        return new self(
            Arr::string($data, 'task'),
            Arr::string($data, 'title'),
            Arr::string($data, 'provider'),
            Arr::string($data, 'model'),
            Arr::string($data, 'content'),
            Arr::string($data, 'finish_reason'),
            Usage::fromArray($usage),
            Arr::string($data, 'generated_at'),
            $error === '' ? null : $error,
            Arr::strings($data, 'notes'),
        );
    }
}
