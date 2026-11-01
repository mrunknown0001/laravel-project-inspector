<?php

declare(strict_types=1);

namespace LaraInspect\Database;

final class MigrationSummary
{
    /** @var array<string, TableBlueprint> */
    public array $tables = [];

    /** @var list<string> */
    public array $unparsed = [];

    /** @var list<string> file:line of DB::statement()/DB::unprepared() */
    public array $rawStatements = [];

    public int $foreignKeyCount = 0;

    public int $indexCount = 0;

    public function table(string $name): TableBlueprint
    {
        return $this->tables[$name] ??= new TableBlueprint($name);
    }

    /**
     * Columns ending in _id that have neither an index nor a foreign key constraint.
     *
     * @return list<array{table: string, column: string, type: string, file: string, line: int}>
     */
    public function unindexedKeyColumns(): array
    {
        $result = [];
        foreach ($this->tables as $table) {
            foreach ($table->columns as $column => $info) {
                if (!str_ends_with($column, '_id') || $table->isIndexed($column) || $table->hasForeignKey($column)) {
                    continue;
                }
                $result[] = ['table' => $table->name, 'column' => $column, 'type' => $info['type'], 'file' => $info['file'], 'line' => $info['line']];
            }
        }

        return $result;
    }

    /**
     * foreignId()-style columns declared without ->constrained().
     *
     * @return list<array{table: string, column: string, file: string, line: int}>
     */
    public function unconstrainedForeignIds(): array
    {
        $result = [];
        foreach ($this->tables as $table) {
            foreach ($table->columns as $column => $info) {
                if (str_starts_with($info['type'], 'foreign') && !$table->hasForeignKey($column)) {
                    $result[] = ['table' => $table->name, 'column' => $column, 'file' => $info['file'], 'line' => $info['line']];
                }
            }
        }

        return $result;
    }

    /**
     * Constrained columns without an explicit index (PostgreSQL does not create one for foreign keys).
     *
     * @return list<array{table: string, column: string, file: string, line: int}>
     */
    public function foreignKeysWithoutIndex(): array
    {
        $result = [];
        foreach ($this->tables as $table) {
            foreach ($table->columns as $column => $info) {
                if ($table->hasForeignKey($column) && !$table->isIndexed($column)) {
                    $result[] = ['table' => $table->name, 'column' => $column, 'file' => $info['file'], 'line' => $info['line']];
                }
            }
        }

        return $result;
    }
}
