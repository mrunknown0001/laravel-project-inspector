<?php

declare(strict_types=1);

namespace LaraInspect\Model;

enum Severity: string
{
    case Critical = 'CRITICAL';
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';
    case Info = 'INFO';

    /**
     * Higher weight means more severe; used for sorting and thresholds.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtoupper(trim($name)))
            ?? throw new \InvalidArgumentException(sprintf(
                'Unknown severity "%s". Expected one of: %s.',
                $name,
                implode(', ', array_map(static fn (self $s): string => $s->value, self::cases())),
            ));
    }

    /**
     * Maps a Packagist advisory severity (critical/high/medium/low) onto ours.
     */
    public static function fromAdvisory(?string $severity): self
    {
        return match (strtolower((string) $severity)) {
            'critical' => self::Critical,
            'high' => self::High,
            'low' => self::Low,
            default => self::Medium,
        };
    }

    /**
     * @return list<self> Most severe first.
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
