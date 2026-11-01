<?php

declare(strict_types=1);

namespace LaraInspect\AI\DTO;

final readonly class ChatMessage
{
    public const SYSTEM = 'system';
    public const USER = 'user';
    public const ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {
        if (!in_array($role, [self::SYSTEM, self::USER, self::ASSISTANT], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown chat role "%s".', $role));
        }
    }

    public static function system(string $content): self
    {
        return new self(self::SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }
}
