<?php

declare(strict_types=1);

namespace LaraInspect\Support;

/**
 * Typed accessors for decoded JSON arrays (saved reports, composer files).
 *
 * @internal
 */
final class Arr
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function listOfArrays(array $data, string $key): array
    {
        $result = [];
        foreach (self::array($data, $key) as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        return array_values(array_map(
            static fn (mixed $v): string => (string) $v,
            array_filter(self::array($data, $key), 'is_scalar'),
        ));
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $result = [];
        foreach (self::array($data, $key) as $k => $v) {
            if (is_scalar($v)) {
                $result[(string) $k] = (string) $v;
            }
        }

        return $result;
    }
}
