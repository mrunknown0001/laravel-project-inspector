<?php

declare(strict_types=1);

namespace LaraInspect\Database;

/**
 * Everything the migrations say about one table, merged across files.
 */
final class TableBlueprint
{
    public ?string $createdIn = null;

    /** @var list<string> */
    public array $alteredIn = [];

    /** @var array<string, array{type: string, file: string, line: int, indexed: bool, constrained: bool}> */
    public array $columns = [];

    /** @var list<list<string>> Column lists of index(), unique() and primary() calls. */
    public array $indexes = [];

    /** @var array<string, true> Columns covered by $table->foreign(). */
    public array $foreignKeys = [];

    public function __construct(
        public readonly string $name,
    ) {
    }

    public function addColumn(string $name, string $type, string $file, int $line, bool $indexed, bool $constrained): void
    {
        $this->columns[$name] = ['type' => $type, 'file' => $file, 'line' => $line, 'indexed' => $indexed, 'constrained' => $constrained];
    }

    public function isIndexed(string $column): bool
    {
        if (($this->columns[$column]['indexed'] ?? false) === true) {
            return true;
        }
        foreach ($this->indexes as $columns) {
            // A composite index only helps lookups on its leading column.
            if (($columns[0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function hasForeignKey(string $column): bool
    {
        return ($this->columns[$column]['constrained'] ?? false) === true || isset($this->foreignKeys[$column]);
    }

    public function foreignKeyCount(): int
    {
        $columns = array_keys(array_filter($this->columns, static fn (array $c): bool => $c['constrained']));

        return count(array_unique([...$columns, ...array_keys($this->foreignKeys)]));
    }
}
