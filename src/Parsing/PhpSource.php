<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

use LaraInspect\Exception\FileAccessException;
use LaraInspect\Security\SafeFileReader;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses project PHP files into syntax trees, and evaluates the small subset
 * of expressions that can be known without running code (literals, ::class,
 * concatenations of those).
 */
final class PhpSource
{
    public const DEFAULT_MAX_BYTES = 1_048_576;

    private readonly Parser $parser;

    private ?string $lastError = null;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return list<Node\Stmt>|null Statements with names resolved to FQCNs; null on parse error.
     */
    public function parse(string $code): ?array
    {
        $this->lastError = null;
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (ParserError $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
        /** @var list<Node\Stmt> $resolved */
        $resolved = $traverser->traverse($stmts);

        return $resolved;
    }

    /**
     * @return list<Node\Stmt>|null
     */
    public function parseFile(SafeFileReader $files, string $path, int $maxBytes = self::DEFAULT_MAX_BYTES): ?array
    {
        try {
            return $this->parse($files->read($path, $maxBytes));
        } catch (FileAccessException $e) {
            $this->lastError = $e->getMessage();

            return null;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Value of a string-like expression: 'x', "x", 'a' . 'b', Foo::class; null if dynamic.
     */
    public static function string(?Node $expr): ?string
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\Int_ => (string) $expr->value,
            $expr instanceof Expr\ClassConstFetch => self::classConstant($expr),
            $expr instanceof Expr\BinaryOp\Concat => self::concat($expr),
            $expr instanceof Scalar\InterpolatedString => self::interpolated($expr),
            default => null,
        };
    }

    /**
     * A string, or an array of strings, as a list. Dynamic entries are skipped.
     *
     * @return list<string>
     */
    public static function strings(?Node $expr): array
    {
        if ($expr instanceof Expr\Array_) {
            $values = [];
            foreach ($expr->items as $item) {
                $value = self::string($item->value);
                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return $values;
        }
        $value = self::string($expr);

        return $value === null ? [] : [$value];
    }

    /**
     * Fully-qualified class name from a Name node or Foo::class expression.
     */
    public static function className(?Node $node): ?string
    {
        if ($node instanceof Node\Name) {
            $resolved = $node->getAttribute('resolvedName');

            return ltrim(($resolved instanceof Node\Name ? $resolved : $node)->toString(), '\\');
        }
        if ($node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'class') {
            return self::className($node->class);
        }

        return null;
    }

    /**
     * Short class name as written in the source (before name resolution), e.g. "Route".
     */
    public static function shortName(Node\Name $name): string
    {
        $original = $name->getAttribute('originalName');
        $text = ($original instanceof Node\Name ? $original : $name)->toString();
        $parts = explode('\\', $text);

        return end($parts) ?: $text;
    }

    /**
     * An argument by position or name (PHP 8 named arguments).
     */
    public static function arg(Expr\CallLike $call, int $position, ?string $name = null): ?Expr
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        $positional = 0;
        foreach ($call->getArgs() as $arg) {
            if ($arg->name !== null) {
                if ($name !== null && $arg->name->toString() === $name) {
                    return $arg->value;
                }
                continue;
            }
            if ($positional === $position) {
                return $arg->value;
            }
            $positional++;
        }

        return null;
    }

    /**
     * Flattens a fluent chain such as Route::middleware('x')->prefix('y')->group(...).
     *
     * @return array{root: Node, calls: list<array{name: string, call: Expr\CallLike}>}|null
     *         Root is the class name (static call) or variable; calls are ordered left to right.
     */
    public static function chain(Expr $expr): ?array
    {
        $calls = [];
        $node = $expr;
        while ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            if (!$node->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, ['name' => $node->name->toString(), 'call' => $node]);
            $node = $node->var;
        }
        if ($node instanceof Expr\StaticCall) {
            if (!$node->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, ['name' => $node->name->toString(), 'call' => $node]);

            return ['root' => $node->class, 'calls' => $calls];
        }

        return $calls === [] ? null : ['root' => $node, 'calls' => $calls];
    }

    /**
     * Name of a called function or Class::method, for pattern matching ("exec", "DB::statement").
     */
    public static function callName(Node $node): ?string
    {
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            return strtolower($node->name->toString());
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            return self::shortName($node->class) . '::' . $node->name->toString();
        }

        return null;
    }

    private static function classConstant(Expr\ClassConstFetch $expr): ?string
    {
        return self::className($expr);
    }

    private static function concat(Expr\BinaryOp\Concat $expr): ?string
    {
        $left = self::string($expr->left);
        $right = self::string($expr->right);

        return $left === null || $right === null ? null : $left . $right;
    }

    private static function interpolated(Scalar\InterpolatedString $expr): ?string
    {
        $value = '';
        foreach ($expr->parts as $part) {
            if (!$part instanceof Node\InterpolatedStringPart) {
                return null;
            }
            $value .= $part->value;
        }

        return $value;
    }
}
