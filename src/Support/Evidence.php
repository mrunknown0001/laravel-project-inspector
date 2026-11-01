<?php

declare(strict_types=1);

namespace LaraInspect\Support;

/**
 * Formatting helpers for finding evidence, so every analyzer presents it the same way.
 */
final class Evidence
{
    public const MAX_ITEMS = 15;

    private const MAX_SNIPPET = 160;

    /**
     * Bulleted list, truncated after MAX_ITEMS entries.
     *
     * @param list<string> $items
     */
    public static function list(array $items, int $max = self::MAX_ITEMS): string
    {
        if ($items === []) {
            return '';
        }
        $shown = array_slice($items, 0, $max);
        $text = '- ' . implode("\n- ", $shown);
        if (count($items) > count($shown)) {
            $text .= sprintf("\n- ... and %d more", count($items) - count($shown));
        }

        return $text;
    }

    /**
     * "app/Http/Kernel.php:42: <code>" with whitespace collapsed and long lines shortened.
     */
    public static function location(string $file, ?int $line, string $snippet = ''): string
    {
        // A label such as "app/X.php (not tracked by git)" keeps its note at the end.
        $note = '';
        if (preg_match('/^(.+?) \(([^()]+)\)$/', $file, $m) === 1) {
            [$file, $note] = [$m[1], ' (' . $m[2] . ')'];
        }
        $where = $line !== null && $line > 0 ? sprintf('%s:%d', $file, $line) : $file;
        $snippet = trim((string) preg_replace('/\s+/', ' ', $snippet));
        if ($snippet === '') {
            return $where . $note;
        }
        if (mb_strlen($snippet) > self::MAX_SNIPPET) {
            $snippet = mb_substr($snippet, 0, self::MAX_SNIPPET - 3) . '...';
        }

        return $where . ': ' . $snippet . $note;
    }

    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return sprintf('%d %s', $count, $count === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}
