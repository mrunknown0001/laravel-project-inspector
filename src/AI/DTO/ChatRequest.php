<?php

declare(strict_types=1);

namespace LaraInspect\AI\DTO;

/**
 * Provider-independent chat request. Adapters translate it to their wire format.
 */
final readonly class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages
     * @param float|null        $temperature Sent only by providers and models that accept it; null = provider default.
     */
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
    ) {
        if ($messages === []) {
            throw new \InvalidArgumentException('A chat request needs at least one message.');
        }
    }

    public function withModel(string $model): self
    {
        return new self($this->messages, $model, $this->maxTokens, $this->temperature);
    }

    /**
     * System messages joined into one string (for APIs with a separate system field).
     */
    public function systemPrompt(): string
    {
        $parts = array_map(static fn (ChatMessage $m): string => $m->content, array_filter($this->messages, static fn (ChatMessage $m): bool => $m->role === ChatMessage::SYSTEM));

        return implode("\n\n", $parts);
    }

    /**
     * @return list<ChatMessage>
     */
    public function conversation(): array
    {
        return array_values(array_filter($this->messages, static fn (ChatMessage $m): bool => $m->role !== ChatMessage::SYSTEM));
    }

    public function characterCount(): int
    {
        return array_sum(array_map(static fn (ChatMessage $m): int => mb_strlen($m->content), $this->messages));
    }
}
