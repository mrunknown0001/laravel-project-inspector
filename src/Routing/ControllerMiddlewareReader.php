<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Support\ClassLocator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds middleware declared inside controllers, statically:
 *  - $this->middleware('auth')->except([...]) in the constructor (Laravel <= 10)
 *  - public static function middleware(): array (HasMiddleware, Laravel 11+)
 *  - #[Middleware('auth')] / #[Authorize(...)] attributes (Laravel 13)
 * Parent classes inside the project are followed.
 */
final class ControllerMiddlewareReader
{
    private const MAX_PARENT_DEPTH = 4;

    /** @var array<string, list<array{middleware: string, only: list<string>, except: list<string>}>> */
    private array $cache = [];

    public function __construct(
        private readonly SafeFileReader $files,
        private readonly ClassLocator $locator,
        private readonly PhpSource $php = new PhpSource(),
    ) {
    }

    /**
     * Middleware that applies to one controller method.
     *
     * @return list<string>
     */
    public function forMethod(string $class, ?string $method): array
    {
        $method ??= '__invoke';
        $result = [];
        foreach ($this->declarations($class, 0) as $declaration) {
            if ($declaration['only'] !== [] && !in_array($method, $declaration['only'], true)) {
                continue;
            }
            if (in_array($method, $declaration['except'], true)) {
                continue;
            }
            $result[] = $declaration['middleware'];
        }

        return array_values(array_unique($result));
    }

    /**
     * @return list<array{middleware: string, only: list<string>, except: list<string>}>
     */
    private function declarations(string $class, int $depth): array
    {
        $class = ltrim($class, '\\');
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }
        $this->cache[$class] = [];

        $path = $this->locator->locate($class);
        $stmts = $path !== null ? $this->php->parseFile($this->files, $path) : null;
        if ($stmts === null) {
            return [];
        }

        $node = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Stmt\Class_ && $n->namespacedName?->toString() === $class)
            ?? (new NodeFinder())->findFirstInstanceOf($stmts, Stmt\Class_::class);
        if (!$node instanceof Stmt\Class_) {
            return [];
        }

        $declarations = $this->fromAttributes($node->attrGroups, []);
        foreach ($node->getMethods() as $method) {
            $name = $method->name->toString();
            array_push($declarations, ...$this->fromAttributes($method->attrGroups, [$name]));
            if ($name === '__construct') {
                array_push($declarations, ...$this->fromConstructor($method));
            }
            if ($name === 'middleware' && $method->isStatic()) {
                array_push($declarations, ...$this->fromStaticMiddleware($method));
            }
        }

        $parent = PhpSource::className($node->extends);
        if ($parent !== null && $depth < self::MAX_PARENT_DEPTH && $this->locator->isProjectClass($parent)) {
            array_push($declarations, ...$this->declarations($parent, $depth + 1));
        }

        return $this->cache[$class] = $declarations;
    }

    /**
     * @param array<Node\AttributeGroup> $groups
     * @param list<string>              $only
     * @return list<array{middleware: string, only: list<string>, except: list<string>}>
     */
    private function fromAttributes(array $groups, array $only): array
    {
        $result = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = PhpSource::shortName($attribute->name);
                $first = $attribute->args[0]->value ?? null;
                $named = [];
                foreach ($attribute->args as $arg) {
                    if ($arg->name !== null) {
                        $named[$arg->name->toString()] = $arg->value;
                    }
                }
                $attrOnly = $only !== [] ? $only : PhpSource::strings($named['only'] ?? null);
                $except = PhpSource::strings($named['except'] ?? null);

                if ($name === 'Middleware') {
                    foreach (PhpSource::strings($first) as $middleware) {
                        $result[] = ['middleware' => $middleware, 'only' => $attrOnly, 'except' => $except];
                    }
                } elseif ($name === 'Authorize') {
                    $ability = PhpSource::string($first);
                    $result[] = ['middleware' => 'can:' . ($ability ?? 'ability'), 'only' => $attrOnly, 'except' => $except];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<array{middleware: string, only: list<string>, except: list<string>}>
     */
    private function fromConstructor(Stmt\ClassMethod $method): array
    {
        $result = [];
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Stmt\Expression::class) as $statement) {
            $chain = $statement->expr instanceof Expr ? PhpSource::chain($statement->expr) : null;
            if ($chain === null || !$chain['root'] instanceof Expr\Variable || $chain['root']->name !== 'this') {
                continue;
            }
            $calls = $chain['calls'];
            if (($calls[0]['name'] ?? null) !== 'middleware') {
                continue;
            }
            $only = [];
            $except = [];
            foreach (array_slice($calls, 1) as $modifier) {
                $values = $this->stringArgs($modifier['call']);
                if ($modifier['name'] === 'only') {
                    $only = $values;
                } elseif ($modifier['name'] === 'except') {
                    $except = $values;
                }
            }
            foreach ($this->stringArgs($calls[0]['call']) as $middleware) {
                $result[] = ['middleware' => $middleware, 'only' => $only, 'except' => $except];
            }
        }

        return $result;
    }

    /**
     * @return list<array{middleware: string, only: list<string>, except: list<string>}>
     */
    private function fromStaticMiddleware(Stmt\ClassMethod $method): array
    {
        $result = [];
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Stmt\Return_::class) as $return) {
            if (!$return->expr instanceof Expr\Array_) {
                continue;
            }
            foreach ($return->expr->items as $item) {
                $value = $item->value;
                if ($value instanceof Expr\New_ && $value->class instanceof Node\Name && PhpSource::shortName($value->class) === 'Middleware') {
                    $only = PhpSource::strings(PhpSource::arg($value, 99, 'only'));
                    $except = PhpSource::strings(PhpSource::arg($value, 99, 'except'));
                    foreach (PhpSource::strings(PhpSource::arg($value, 0, 'middleware')) as $middleware) {
                        $result[] = ['middleware' => $middleware, 'only' => $only, 'except' => $except];
                    }
                    continue;
                }
                foreach (PhpSource::strings($value) as $middleware) {
                    $result[] = ['middleware' => $middleware, 'only' => [], 'except' => []];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stringArgs(Expr\CallLike $call): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }
        $values = [];
        foreach ($call->getArgs() as $arg) {
            array_push($values, ...PhpSource::strings($arg->value));
        }

        return $values;
    }
}
