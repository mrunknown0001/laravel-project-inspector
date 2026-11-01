<?php

declare(strict_types=1);

namespace LaraInspect\Database;

use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\SafeFileReader;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Reads Schema::create()/Schema::table() blueprints from migration files statically.
 */
final class MigrationParser
{
    /** Column types whose name may hold a foreign key. */
    private const KEY_TYPES = [
        'unsignedbiginteger', 'biginteger', 'unsignedinteger', 'integer', 'unsignedmediuminteger', 'mediuminteger',
        'unsignedsmallinteger', 'smallinteger', 'uuid', 'ulid', 'string', 'char',
    ];

    private const FOREIGN_ID_TYPES = ['foreignid', 'foreignuuid', 'foreignulid', 'foreignidfor'];

    private const MORPH_TYPES = ['morphs', 'nullablemorphs', 'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs', 'nullableulidmorphs', 'numericmorphs', 'nullablenumericmorphs'];

    private const INDEX_METHODS = ['index', 'unique', 'primary', 'fulltext', 'spatialindex', 'rawindex'];

    private const PRIMARY_TYPES = ['id', 'increments', 'bigincrements', 'mediumincrements', 'smallincrements', 'tinyincrements'];

    private readonly PhpSource $php;

    public function __construct(
        private readonly SafeFileReader $files,
    ) {
        $this->php = new PhpSource();
    }

    /**
     * @param list<string> $migrationFiles
     */
    public function parse(array $migrationFiles): MigrationSummary
    {
        $summary = new MigrationSummary();
        foreach ($migrationFiles as $file) {
            $stmts = $this->php->parseFile($this->files, $file);
            if ($stmts === null) {
                $summary->unparsed[] = $file;
                continue;
            }
            $this->parseFile($stmts, $file, $summary);
        }

        return $summary;
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function parseFile(array $stmts, string $file, MigrationSummary $summary): void
    {
        $finder = new NodeFinder();
        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Expr\StaticCall || $n instanceof Expr\MethodCall) as $call) {
            /** @var Expr\StaticCall|Expr\MethodCall $call */
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            if (!in_array($name, ['create', 'table'], true) || !$this->isSchemaCall($call)) {
                continue;
            }
            $table = PhpSource::string(PhpSource::arg($call, 0));
            $callback = PhpSource::arg($call, 1);
            if ($table === null || !($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction)) {
                continue;
            }
            $param = $callback->params[0]->var ?? null;
            $variable = $param instanceof Expr\Variable && is_string($param->name) ? $param->name : 'table';

            $blueprint = $summary->table($table);
            if ($name === 'create') {
                $blueprint->createdIn ??= $file;
            } else {
                $blueprint->alteredIn[] = $file;
            }
            $this->parseBlueprint($callback, $variable, $blueprint, $file, $summary);
        }

        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Expr\StaticCall) as $call) {
            /** @var Expr\StaticCall $call */
            $callName = PhpSource::callName($call);
            if (in_array($callName, ['DB::statement', 'DB::unprepared'], true)) {
                $summary->rawStatements[] = sprintf('%s:%d', $file, $call->getStartLine());
            }
        }
    }

    private function isSchemaCall(Expr\StaticCall|Expr\MethodCall $call): bool
    {
        if ($call instanceof Expr\StaticCall) {
            return $call->class instanceof Node\Name && PhpSource::shortName($call->class) === 'Schema';
        }
        // Schema::connection('x')->create(...)
        $chain = PhpSource::chain($call);

        return $chain !== null && $chain['root'] instanceof Node\Name && PhpSource::shortName($chain['root']) === 'Schema';
    }

    private function parseBlueprint(Expr\Closure|Expr\ArrowFunction $callback, string $variable, TableBlueprint $table, string $file, MigrationSummary $summary): void
    {
        // Closures hold statements; arrow functions hold a single expression.
        $expressions = $callback instanceof Expr\Closure
            ? array_map(
                static fn (Node\Stmt\Expression $s): Expr => $s->expr,
                (new NodeFinder())->findInstanceOf($callback->stmts, Node\Stmt\Expression::class),
            )
            : [$callback->expr];
        foreach ($expressions as $expression) {
            $chain = PhpSource::chain($expression);
            if ($chain === null || !$chain['root'] instanceof Expr\Variable || $chain['root']->name !== $variable) {
                continue;
            }
            $this->parseChain($chain['calls'], $table, $file, $expression->getStartLine(), $summary);
        }
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $calls
     */
    private function parseChain(array $calls, TableBlueprint $table, string $file, int $line, MigrationSummary $summary): void
    {
        $method = strtolower($calls[0]['name']);
        $call = $calls[0]['call'];
        $modifiers = array_map(static fn (array $c): string => strtolower($c['name']), array_slice($calls, 1));
        $indexed = array_intersect($modifiers, ['index', 'unique', 'primary']) !== [];
        $constrained = array_intersect($modifiers, ['constrained', 'references']) !== [];

        if (in_array($method, self::INDEX_METHODS, true)) {
            $columns = PhpSource::strings(PhpSource::arg($call, 0));
            if ($columns !== []) {
                $table->indexes[] = $columns;
                $summary->indexCount++;
            }

            return;
        }
        if ($method === 'foreign') {
            $columns = PhpSource::strings(PhpSource::arg($call, 0));
            foreach ($columns as $column) {
                $table->foreignKeys[$column] = true;
            }
            $summary->foreignKeyCount++;

            return;
        }
        if (in_array($method, self::PRIMARY_TYPES, true)) {
            $table->addColumn(PhpSource::string(PhpSource::arg($call, 0)) ?? 'id', $method, $file, $line, true, false);

            return;
        }
        if (in_array($method, self::MORPH_TYPES, true)) {
            $name = PhpSource::string(PhpSource::arg($call, 0));
            if ($name !== null) {
                // morphs() adds a composite index on (type, id) unless told otherwise.
                $table->addColumn($name . '_id', $method, $file, $line, true, false);
                $summary->indexCount++;
            }

            return;
        }
        if (in_array($method, self::FOREIGN_ID_TYPES, true)) {
            $column = $method === 'foreignidfor'
                ? (PhpSource::string(PhpSource::arg($call, 1)) ?? $this->foreignIdForColumn(PhpSource::arg($call, 0)))
                : PhpSource::string(PhpSource::arg($call, 0));
            if ($column !== null) {
                $table->addColumn($column, $method, $file, $line, $indexed, $constrained);
                if ($constrained) {
                    $summary->foreignKeyCount++;
                }
                if ($indexed) {
                    $summary->indexCount++;
                }
            }

            return;
        }
        if (in_array($method, self::KEY_TYPES, true)) {
            $column = PhpSource::string(PhpSource::arg($call, 0));
            if ($column !== null) {
                $table->addColumn($column, $method, $file, $line, $indexed, $constrained);
                if ($indexed) {
                    $summary->indexCount++;
                }
            }
        }
    }

    private function foreignIdForColumn(?Expr $model): ?string
    {
        $class = PhpSource::className($model);
        if ($class === null) {
            return null;
        }
        $parts = explode('\\', $class);
        $base = (string) end($parts);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base)) . '_id';
    }
}
