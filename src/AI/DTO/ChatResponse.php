<?php

declare(strict_types=1);

namespace LaraInspect\AI\DTO;

final readonly class ChatResponse
{
    /** Normalised finish reasons. */
    public const FINISH_STOP = 'stop';
    public const FINISH_LENGTH = 'length';
    public const FINISH_REFUSAL = 'refusal';
    public const FINISH_FILTERED = 'content_filter';
    public const FINISH_OTHER = 'other';

    /**
     * @param string      $finishReason One of the FINISH_* constants.
     * @param string|null $refusal      Explanation or category when the model declined.
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public string $finishReason,
        public Usage $usage,
        public ?string $id = null,
        public ?string $refusal = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->finishReason === self::FINISH_STOP;
    }
}
