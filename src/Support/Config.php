<?php

declare(strict_types=1);

namespace LaraInspect\Support;

/**
 * The inspector's own configuration (config/*.php of this tool, never of the target).
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $values,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Inspector configuration file "%s" is missing.', $file));
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException(sprintf('Inspector configuration file "%s" must return an array.', $file));
        }

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }
}
