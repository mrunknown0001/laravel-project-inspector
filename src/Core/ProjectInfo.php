<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Model\Source;
use LaraInspect\Support\Arr;

/**
 * Result of project detection: what the directory is and which Laravel version it uses.
 */
final readonly class ProjectInfo
{
    public const LAYOUT_MODERN = 'modern';
    public const LAYOUT_LEGACY = 'legacy';
    public const LAYOUT_UNKNOWN = 'unknown';

    /**
     * @param array<string, bool> $markers  Marker path => present.
     * @param list<string>        $warnings
     */
    public function __construct(
        public string $root,
        public string $name,
        public bool $isLaravel,
        public ?string $framework,
        public ?string $laravelVersion,
        public ?int $laravelMajor,
        public Source $versionSource,
        public ?string $versionOrigin,
        public string $layout,
        public array $markers,
        public array $warnings = [],
    ) {
    }

    public function isSupportedVersion(): bool
    {
        return $this->laravelMajor !== null && $this->laravelMajor >= ProjectDetector::MINIMUM_SUPPORTED_MAJOR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'name' => $this->name,
            'is_laravel' => $this->isLaravel,
            'framework' => $this->framework,
            'laravel_version' => $this->laravelVersion,
            'laravel_major' => $this->laravelMajor,
            'version_source' => $this->versionSource->value,
            'version_origin' => $this->versionOrigin,
            'layout' => $this->layout,
            'markers' => $this->markers,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $markers = [];
        foreach (Arr::array($data, 'markers') as $path => $present) {
            $markers[(string) $path] = (bool) $present;
        }
        $nullable = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;

        return new self(
            Arr::string($data, 'root'),
            Arr::string($data, 'name'),
            Arr::bool($data, 'is_laravel'),
            $nullable('framework'),
            $nullable('laravel_version'),
            isset($data['laravel_major']) && is_int($data['laravel_major']) ? $data['laravel_major'] : null,
            Source::tryFrom(Arr::string($data, 'version_source')) ?? Source::Unknown,
            $nullable('version_origin'),
            Arr::string($data, 'layout', self::LAYOUT_UNKNOWN),
            $markers,
            Arr::strings($data, 'warnings'),
        );
    }
}
