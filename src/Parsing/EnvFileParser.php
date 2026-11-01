<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

/**
 * Minimal dotenv parser.
 *
 * Deliberately does NOT perform variable interpolation or command substitution
 * (unlike some dotenv libraries that support "$(command)"), so parsing an
 * untrusted .env file can never execute anything.
 */
final class EnvFileParser
{
    /**
     * @return array<string, string> Raw key => value pairs, last definition wins.
     */
    public function parse(string $contents): array
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $lines = explode("\n", $contents);
        $values = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $key = $m[1];
            $raw = $m[2];
            $quote = $raw[0] ?? '';

            if ($quote === '"' || $quote === "'") {
                $body = substr($raw, 1);
                // Multi-line quoted values continue until an unescaped closing quote.
                while (!$this->hasClosingQuote($body, $quote) && $i + 1 < $count) {
                    $body .= "\n" . $lines[++$i];
                }
                $values[$key] = $this->unquote($body, $quote);
                continue;
            }

            $values[$key] = trim((string) preg_replace('/\s+#.*$/', '', $raw));
        }

        return $values;
    }

    private function hasClosingQuote(string $body, string $quote): bool
    {
        return $this->closingQuotePosition($body, $quote) !== null;
    }

    private function closingQuotePosition(string $body, string $quote): ?int
    {
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $body[$i] === '\\') {
                $i++;
                continue;
            }
            if ($body[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private function unquote(string $body, string $quote): string
    {
        $end = $this->closingQuotePosition($body, $quote);
        $value = $end === null ? $body : substr($body, 0, $end);

        if ($quote === "'") {
            return $value;
        }

        return strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
    }
}
