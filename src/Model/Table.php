<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Support\Arr;

final readonly class Table
{
    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function __construct(
        public string $title,
        public array $headers,
        public array $rows,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'headers' => $this->headers, 'rows' => $this->rows];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (Arr::array($data, 'rows') as $row) {
            if (is_array($row)) {
                $rows[] = array_values(array_map(static fn (mixed $c): string => is_scalar($c) ? (string) $c : '', $row));
            }
        }

        return new self(Arr::string($data, 'title'), Arr::strings($data, 'headers'), $rows);
    }
}
