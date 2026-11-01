<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

use LaraInspect\Exception\FileAccessException;
use LaraInspect\Model\Source;
use LaraInspect\Security\SafeFileReader;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads Laravel config/*.php files statically.
 *
 * Config files are parsed into an AST and never included, so no project code
 * runs. Literal values are "detected"; env('KEY', default) is resolved against
 * the allowlisted .env view, falling back to the default as "inferred".
 * Anything else (function calls, concatenation) is reported as unknown.
 */
final class ConfigReader
{
    private readonly Parser $parser;

    /** @var array<string, Expr\Array_|string> file => root array, or an error message */
    private array $cache = [];

    public function __construct(
        private readonly SafeFileReader $files,
        private readonly EnvFile $env,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function exists(string $file): bool
    {
        return $this->files->isFile(self::path($file));
    }

    /**
     * @param string $file    Config name without extension, e.g. "database".
     * @param string $keyPath Dot-separated path, e.g. "connections.mysql.driver".
     */
    public function get(string $file, string $keyPath): ConfigValue
    {
        $origin = self::path($file) . ':' . $keyPath;
        $root = $this->root($file);
        if (is_string($root)) {
            return ConfigValue::missing($root);
        }

        $node = $this->find($root, $keyPath);
        if ($node === null) {
            return ConfigValue::missing($origin . ' (not defined)');
        }

        return $this->resolve($node, $origin);
    }

    /**
     * Items of a literal array, e.g. list("cors", "allowed_origins"); null if the
     * value is missing or not a literal array.
     *
     * @return list<ConfigValue>|null
     */
    public function list(string $file, string $keyPath): ?array
    {
        $root = $this->root($file);
        if (is_string($root)) {
            return null;
        }
        $node = $this->find($root, $keyPath);
        if (!$node instanceof Expr\Array_) {
            return null;
        }
        $origin = self::path($file) . ':' . $keyPath;
        $items = [];
        foreach ($node->items as $item) {
            $items[] = $this->resolve($item->value, $origin);
        }

        return $items;
    }

    /**
     * Keys of the array at a path, e.g. keys("database", "connections").
     *
     * @return list<string>
     */
    public function keys(string $file, string $keyPath = ''): array
    {
        $root = $this->root($file);
        if (is_string($root)) {
            return [];
        }
        $node = $keyPath === '' ? $root : $this->find($root, $keyPath);
        if (!$node instanceof Expr\Array_) {
            return [];
        }

        $keys = [];
        foreach ($node->items as $item) {
            if ($item->key instanceof Scalar\String_) {
                $keys[] = $item->key->value;
            }
        }

        return $keys;
    }

    private static function path(string $file): string
    {
        return 'config/' . $file . '.php';
    }

    private function root(string $file): Expr\Array_|string
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }

        $path = self::path($file);
        try {
            $code = $this->files->read($path);
            $stmts = $this->parser->parse($code) ?? [];
        } catch (FileAccessException $e) {
            return $this->cache[$file] = $e->getMessage();
        } catch (ParserError $e) {
            return $this->cache[$file] = sprintf('%s could not be parsed: %s', $path, $e->getMessage());
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof Expr\Array_) {
                return $this->cache[$file] = $stmt->expr;
            }
        }

        return $this->cache[$file] = sprintf('%s does not return a literal array.', $path);
    }

    private function find(Expr\Array_ $array, string $keyPath): ?Expr
    {
        $node = $array;
        foreach (explode('.', $keyPath) as $segment) {
            if (!$node instanceof Expr\Array_) {
                return null;
            }
            $next = null;
            foreach ($node->items as $item) {
                if ($item->key instanceof Scalar\String_ && $item->key->value === $segment) {
                    $next = $item->value;
                }
            }
            if ($next === null) {
                return null;
            }
            $node = $next;
        }

        return $node;
    }

    private function resolve(Node $node, string $origin): ConfigValue
    {
        return match (true) {
            $node instanceof Scalar\String_,
            $node instanceof Scalar\Int_,
            $node instanceof Scalar\Float_ => new ConfigValue($node->value, Source::Detected, $origin),
            $node instanceof Expr\ConstFetch => $this->resolveConstant($node, $origin),
            $node instanceof Expr\UnaryMinus && ($node->expr instanceof Scalar\Int_ || $node->expr instanceof Scalar\Float_)
                => new ConfigValue(-$node->expr->value, Source::Detected, $origin),
            $node instanceof Expr\Cast => $this->resolveCast($node, $origin),
            $node instanceof Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'env'
                => $this->resolveEnv($node, $origin),
            default => ConfigValue::unknown($origin . ' (computed at runtime)'),
        };
    }

    private function resolveConstant(Expr\ConstFetch $node, string $origin): ConfigValue
    {
        return match (strtolower($node->name->toString())) {
            'true' => new ConfigValue(true, Source::Detected, $origin),
            'false' => new ConfigValue(false, Source::Detected, $origin),
            'null' => new ConfigValue(null, Source::Detected, $origin),
            default => ConfigValue::unknown($origin . ' (constant ' . $node->name->toString() . ')'),
        };
    }

    private function resolveCast(Expr\Cast $node, string $origin): ConfigValue
    {
        $inner = $this->resolve($node->expr, $origin);
        if (!$inner->isKnown()) {
            return $inner;
        }

        $value = match (true) {
            $node instanceof Expr\Cast\Bool_ => (bool) $inner->value,
            $node instanceof Expr\Cast\Int_ => (int) $inner->value,
            $node instanceof Expr\Cast\Double => (float) $inner->value,
            $node instanceof Expr\Cast\String_ => ConfigValue::format($inner->value),
            default => null,
        };
        if ($value === null) {
            return ConfigValue::unknown($origin . ' (unsupported cast)');
        }

        return new ConfigValue($value, $inner->source, $inner->origin, $inner->found);
    }

    private function resolveEnv(Expr\FuncCall $call, string $origin): ConfigValue
    {
        $args = $call->getArgs();
        $keyNode = $args[0]->value ?? null;
        if (!$keyNode instanceof Scalar\String_) {
            return ConfigValue::unknown($origin . ' (dynamic env() key)');
        }
        $key = $keyNode->value;

        if ($this->env->has($key)) {
            if (!$this->env->isReadable($key)) {
                return ConfigValue::unknown(sprintf('%s is set in %s (value not read)', $key, $this->env->origin()));
            }
            $source = $this->env->isExample() ? Source::Inferred : Source::Detected;

            return new ConfigValue($this->env->typed($key), $source, $this->env->origin() . ': ' . $key);
        }

        $defaultNode = $args[1]->value ?? null;
        if ($defaultNode === null) {
            return new ConfigValue(null, Source::Inferred, sprintf("%s (env('%s') unset, no default)", $origin, $key));
        }

        $default = $this->resolve($defaultNode, $origin);
        if (!$default->isKnown()) {
            return $default;
        }
        // A nested env() default, e.g. env('CACHE_STORE', env('CACHE_DRIVER', 'file')),
        // already carries its own origin and source; keep them.
        if ($default->origin !== $origin) {
            return $default;
        }

        return new ConfigValue($default->value, Source::Inferred, sprintf("%s (env('%s') default)", $origin, $key));
    }
}
