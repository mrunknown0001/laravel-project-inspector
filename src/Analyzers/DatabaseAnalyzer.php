<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Database\MigrationParser;
use LaraInspect\Database\MigrationSummary;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Parsing\SettingResolver;
use LaraInspect\Support\Evidence;

/**
 * Database technology, connections and the schema described by migrations.
 *
 * The inspector never connects to the database; everything comes from
 * config/database.php and the migration files' syntax trees.
 */
final class DatabaseAnalyzer implements AnalyzerInterface
{
    private const PACKAGES = [
        'doctrine/dbal' => 'Doctrine DBAL (schema introspection, column changes)',
        'laravel/scout' => 'Laravel Scout (full-text search)',
        'mongodb/laravel-mongodb' => 'MongoDB',
        'jenssegers/mongodb' => 'MongoDB (legacy package)',
        'predis/predis' => 'Redis client (Predis)',
        'laravel/horizon' => 'Horizon (Redis queues)',
        'spatie/laravel-backup' => 'Database backups',
        'spatie/laravel-permission' => 'Roles and permissions tables',
        'spatie/laravel-activitylog' => 'Activity log table',
        'owen-it/laravel-auditing' => 'Audit trail tables',
        'staudenmeier/laravel-adjacency-list' => 'Recursive relationships',
        'tpetry/laravel-postgresql-enhanced' => 'PostgreSQL extensions',
        'stancl/tenancy' => 'Multi-tenancy (tenant databases)',
        'spatie/laravel-multitenancy' => 'Multi-tenancy',
        'pgvector/pgvector' => 'pgvector (vector search)',
    ];

    public function name(): string
    {
        return 'database';
    }

    public function title(): string
    {
        return 'Database';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $settings = new SettingResolver($context);
        $config = $context->config();
        $default = $settings->resolve('database');
        $connectionName = $default->asString();
        $driver = $connectionName !== null ? $settings->databaseDriver($connectionName) : null;
        $technology = $driver !== null ? (SettingResolver::DATABASE_DRIVERS[$driver] ?? $driver) : null;

        $connections = [];
        foreach ($config->keys('database', 'connections') as $connection) {
            $connectionDriver = $config->get('database', 'connections.' . $connection . '.driver');
            $connections[] = [$connection, $connectionDriver->display(), $connection === $connectionName ? 'default' : ''];
        }

        $migrationFiles = $context->files->findFiles('database/migrations', ['php']);
        $summary = (new MigrationParser($context->files))->parse($migrationFiles);

        $packages = [];
        foreach (self::PACKAGES as $package => $label) {
            if ($context->composer()->lockedPackage($package) !== null || isset($context->composer()->require()[$package])) {
                $packages[] = [$package, $label];
            }
        }

        $created = array_filter($summary->tables, static fn ($t): bool => $t->createdIn !== null);
        $facts = [
            new Fact('Default connection', $default->display(), $default->source, $default->origin),
            new Fact('Database technology', $technology ?? 'Unknown', $technology !== null ? $default->source : Source::Unknown, 'config/database.php'),
            new Fact('Configured connections', $connections === [] ? 'None found in config/database.php' : implode(', ', array_column($connections, 0)), Source::Detected, 'config/database.php'),
            new Fact('Migration files', (string) count($migrationFiles), Source::Detected, 'database/migrations'),
            new Fact('Tables created by migrations', (string) count($created), Source::Detected, 'Schema::create()'),
            new Fact('Foreign key constraints', (string) $summary->foreignKeyCount, Source::Detected, 'constrained() / foreign()'),
            new Fact('Explicit indexes', (string) $summary->indexCount, Source::Detected, 'index() / unique() / primary() / morphs()'),
            new Fact('Seeders', (string) count($context->files->findFiles('database/seeders', ['php'])), Source::Detected, 'database/seeders'),
            new Fact('Factories', (string) count($context->files->findFiles('database/factories', ['php'])), Source::Detected, 'database/factories'),
        ];

        $tableRows = [];
        foreach ($summary->tables as $table) {
            $tableRows[] = [
                $table->name,
                $table->createdIn ?? '(altered only)',
                (string) count($table->columns),
                (string) $table->foreignKeyCount(),
                (string) count($table->indexes),
            ];
        }
        usort($tableRows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $tables = [];
        if ($connections !== []) {
            $tables[] = new Table('Connections', ['Name', 'Driver', ''], $connections);
        }
        $tables[] = new Table('Tables (from migrations)', ['Table', 'Created in', 'Key/index columns seen', 'Foreign keys', 'Indexes'], $tableRows);
        if ($packages !== []) {
            $tables[] = new Table('Database-related packages', ['Package', 'Purpose'], $packages);
        }

        $notes = ['The database was not connected to. Schema details come from migration files only; tables created by packages or by raw SQL may be missing.'];
        if ($summary->unparsed !== []) {
            $notes[] = sprintf('%s could not be parsed: %s.', Evidence::plural(count($summary->unparsed), 'migration'), implode(', ', $summary->unparsed));
        }
        if ($context->files->isFile('database/database.sqlite')) {
            $notes[] = 'database/database.sqlite exists in the project directory.';
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: $this->findings($context, $connectionName, $connections, $driver, $summary, $migrationFiles),
            notes: $notes,
            data: [
                'default_connection' => $connectionName,
                'driver' => $driver,
                'technology' => $technology,
                'connections' => array_column($connections, 1, 0),
                'migrations' => count($migrationFiles),
                'tables' => array_column($tableRows, 0),
                'foreign_keys' => $summary->foreignKeyCount,
                'indexes' => $summary->indexCount,
                'packages' => array_column($packages, 0),
            ],
        );
    }

    /**
     * @param list<list<string>> $connections
     * @param list<string>       $migrationFiles
     * @return list<Finding>
     */
    private function findings(InspectionContext $context, ?string $connectionName, array $connections, ?string $driver, MigrationSummary $summary, array $migrationFiles): array
    {
        $findings = [];
        $names = array_column($connections, 0);

        if ($connectionName !== null && $names !== [] && !in_array($connectionName, $names, true)) {
            $findings[] = new Finding(
                'db.default-connection-undefined',
                sprintf('Default database connection "%s" is not configured', $connectionName),
                Severity::Medium,
                Confidence::Confirmed,
                'config/database.php',
                'The default connection name does not match any entry under "connections", so database access fails at runtime.',
                sprintf('Default: "%s"; configured: %s.', $connectionName, implode(', ', $names)),
                'Set DB_CONNECTION to one of the configured connections, or add the missing connection to config/database.php.',
            );
        }

        $unindexed = $summary->unindexedKeyColumns();
        if ($unindexed !== []) {
            $findings[] = new Finding(
                'db.missing-index',
                sprintf('%s may be missing an index', Evidence::plural(count($unindexed), 'key column')),
                Severity::Low,
                Confidence::Potential,
                'database/migrations',
                'Columns named *_id are usually used in joins and WHERE clauses. These have no index or foreign key constraint in any migration, so lookups on them scan the whole table as it grows. Indexes added outside migrations are not visible.',
                Evidence::list(array_map(static fn (array $c): string => sprintf('%s.%s (%s) %s', $c['table'], $c['column'], $c['type'], Evidence::location($c['file'], $c['line'])), $unindexed)),
                'Add ->index() (or use foreignId()->constrained()) for columns used in queries, in a new migration.',
            );
        }

        $unconstrained = $summary->unconstrainedForeignIds();
        if ($unconstrained !== []) {
            $findings[] = new Finding(
                'db.unconstrained-foreign-id',
                sprintf('%s declared with foreignId() but no constraint', Evidence::plural(count($unconstrained), 'column is', 'columns are')),
                Severity::Info,
                Confidence::Potential,
                'database/migrations',
                'foreignId() alone only creates an unsigned big integer column. Without ->constrained() the database does not enforce that the referenced row exists. This may be intentional, e.g. for polymorphic or cross-database references.',
                Evidence::list(array_map(static fn (array $c): string => sprintf('%s.%s %s', $c['table'], $c['column'], Evidence::location($c['file'], $c['line'])), $unconstrained)),
                'Add ->constrained() (with ->cascadeOnDelete() or ->nullOnDelete() as appropriate) where referential integrity is wanted.',
            );
        }

        if ($driver === 'pgsql') {
            $withoutIndex = $summary->foreignKeysWithoutIndex();
            if ($withoutIndex !== []) {
                $findings[] = new Finding(
                    'db.pgsql-foreign-key-index',
                    sprintf('%s without an index on PostgreSQL', Evidence::plural(count($withoutIndex), 'foreign key column')),
                    Severity::Info,
                    Confidence::Potential,
                    'database/migrations',
                    'Unlike MySQL, PostgreSQL does not create an index for foreign key columns. Joins and cascading deletes on these columns may be slow.',
                    Evidence::list(array_map(static fn (array $c): string => sprintf('%s.%s %s', $c['table'], $c['column'], Evidence::location($c['file'], $c['line'])), $withoutIndex)),
                    'Add ->index() to foreign key columns that are queried or used for cascading deletes.',
                );
            }
        }

        if ($summary->rawStatements !== []) {
            $findings[] = new Finding(
                'db.raw-sql-migrations',
                sprintf('%s raw SQL', Evidence::plural(count($summary->rawStatements), 'migration statement runs', 'migration statements run')),
                Severity::Info,
                Confidence::Confirmed,
                'database/migrations',
                'Raw SQL in migrations is often database-specific and is invisible to this analysis. Review it when changing database engines.',
                Evidence::list($summary->rawStatements),
                'Prefer schema builder methods where possible, and document engine-specific statements.',
            );
        }

        if ($migrationFiles === [] && $context->files->findFiles('app/Models', ['php']) !== []) {
            $findings[] = new Finding(
                'db.no-migrations',
                'Models exist but there are no migrations',
                Severity::Info,
                Confidence::Confirmed,
                'database/migrations',
                'The schema cannot be recreated from the repository, which makes testing and new environments harder. The schema may be managed elsewhere (a dump file, another repository).',
                'app/Models contains classes; database/migrations has no PHP files.',
                'Add migrations (or a schema dump via "php artisan schema:dump") so the schema is versioned with the code.',
            );
        }

        return $findings;
    }
}
