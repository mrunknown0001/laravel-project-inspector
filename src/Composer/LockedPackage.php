<?php

declare(strict_types=1);

namespace LaraInspect\Composer;

use LaraInspect\Support\Arr;

final readonly class LockedPackage
{
    /**
     * @param array<string, string> $require
     * @param string|bool|null      $abandoned true, or the suggested replacement package name.
     * @param list<string>          $provides  Lower-cased names this package replaces or provides.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $type,
        public array $require,
        public bool $dev,
        public string|bool|null $abandoned = null,
        public array $provides = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data A package entry from composer.lock or installed.json.
     */
    public static function fromArray(array $data, bool $dev): self
    {
        $abandoned = $data['abandoned'] ?? null;

        return new self(
            Arr::string($data, 'name'),
            Arr::string($data, 'version'),
            Arr::string($data, 'type', 'library'),
            Arr::stringMap($data, 'require'),
            $dev,
            is_string($abandoned) || is_bool($abandoned) ? $abandoned : null,
            array_values(array_unique(array_map(
                static fn (int|string $name): string => strtolower((string) $name),
                [...array_keys(Arr::array($data, 'replace')), ...array_keys(Arr::array($data, 'provide'))],
            ))),
        );
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned !== null && $this->abandoned !== false;
    }

    public function replacement(): ?string
    {
        return is_string($this->abandoned) && $this->abandoned !== '' ? $this->abandoned : null;
    }

    public function phpRequirement(): ?string
    {
        return $this->require['php'] ?? null;
    }
}
