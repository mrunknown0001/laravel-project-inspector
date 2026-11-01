<?php

declare(strict_types=1);

namespace LaraInspect\AI\Contracts;

use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\DTO\ModelInfo;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\AIException;

/**
 * A chat-completion provider. Implementations translate the normalised
 * request/response objects to one provider's HTTP API.
 */
interface AIProviderInterface
{
    /**
     * Machine name, e.g. "anthropic".
     */
    public function getProviderName(): string;

    /**
     * Problems that prevent use (missing key, model, invalid URL). Makes no network calls.
     *
     * @return list<string> Empty when the configuration is usable.
     */
    public function validateConfiguration(): array;

    /**
     * @return list<ModelInfo>
     *
     * @throws AIException
     */
    public function listModels(): array;

    /**
     * @throws AIException
     */
    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Usage of the most recent successful chat() call, if any.
     */
    public function getLastUsage(): ?Usage;
}
