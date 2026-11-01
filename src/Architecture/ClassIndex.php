<?php

declare(strict_types=1);

namespace LaraInspect\Architecture;

use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\SafeFileReader;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Classes, interfaces, traits and enums declared in the project's own code,
 * plus a few usage counters gathered in the same pass.
 */
final class ClassIndex
{
    /** @var array<string, ClassInfo> */
    private array $classes = [];

    /** @var array<string, int> counter => occurrences */
    private array $usage = [];

    /** @var list<string> */
    private array $unparsed = [];

    /**
     * @param list<string> $paths
     */
    public static function build(SafeFileReader $files, array $paths): self
    {
        $index = new self();
        $php = new PhpSource();
        foreach ($paths as $file) {
            $stmts = $php->parseFile($files, $file);
            if ($stmts === null) {
                $index->unparsed[] = $file;
                continue;
            }
            $index->indexFile($file, $stmts);
        }

        return $index;
    }

    /**
     * @return array<string, ClassInfo>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function get(string $name): ?ClassInfo
    {
        return $this->classes[ltrim($name, '\\')] ?? null;
    }

    /**
     * Every ancestor name: project parents are followed, the first external parent ends the chain.
     *
     * @return list<string>
     */
    public function ancestors(ClassInfo $class): array
    {
        $ancestors = [];
        $current = $class;
        $guard = 0;
        while ($current->parent !== null && $guard++ < 20) {
            $ancestors[] = $current->parent;
            $next = $this->get($current->parent);
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        return $ancestors;
    }

    /**
     * Interfaces implemented by the class or any project ancestor.
     *
     * @return list<string>
     */
    public function allInterfaces(ClassInfo $class): array
    {
        $interfaces = $class->interfaces;
        foreach ($this->ancestors($class) as $ancestor) {
            $info = $this->get($ancestor);
            if ($info !== null) {
                array_push($interfaces, ...$info->interfaces);
            }
        }

        return array_values(array_unique($interfaces));
    }

    public function usage(string $counter): int
    {
        return $this->usage[$counter] ?? 0;
    }

    /**
     * @return list<string>
     */
    public function unparsed(): array
    {
        return $this->unparsed;
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function indexFile(string $file, array $stmts): void
    {
        $finder = new NodeFinder();
        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Stmt\ClassLike) as $node) {
            /** @var Stmt\ClassLike $node */
            if ($node->namespacedName === null) {
                continue; // anonymous classes (e.g. migrations)
            }
            $name = $node->namespacedName->toString();
            $kind = match (true) {
                $node instanceof Stmt\Interface_ => ClassInfo::KIND_INTERFACE,
                $node instanceof Stmt\Trait_ => ClassInfo::KIND_TRAIT,
                $node instanceof Stmt\Enum_ => ClassInfo::KIND_ENUM,
                default => ClassInfo::KIND_CLASS,
            };
            $traits = [];
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    $traits[] = (string) PhpSource::className($trait);
                }
            }
            $publicMethods = count(array_filter($node->getMethods(), static fn (Stmt\ClassMethod $m): bool => $m->isPublic()));

            $this->classes[$name] = new ClassInfo(
                $name,
                $kind,
                $file,
                $node instanceof Stmt\Class_ ? PhpSource::className($node->extends) : null,
                match (true) {
                    $node instanceof Stmt\Class_, $node instanceof Stmt\Enum_ => array_values(array_filter(array_map(PhpSource::className(...), $node->implements))),
                    $node instanceof Stmt\Interface_ => array_values(array_filter(array_map(PhpSource::className(...), $node->extends))),
                    default => [],
                },
                $traits,
                max(0, $node->getEndLine() - $node->getStartLine() + 1),
                $publicMethods,
                $node instanceof Stmt\Class_ && $node->isAbstract(),
            );
        }

        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Expr\StaticCall || $n instanceof Expr\FuncCall || $n instanceof Expr\New_) as $node) {
            $counter = match (true) {
                $node instanceof Expr\StaticCall => match (PhpSource::callName($node)) {
                    'Http::get', 'Http::post', 'Http::put', 'Http::patch', 'Http::delete', 'Http::withToken', 'Http::withHeaders',
                    'Http::baseUrl', 'Http::acceptJson', 'Http::asJson', 'Http::timeout', 'Http::retry', 'Http::withBasicAuth', 'Http::pool' => 'http',
                    'Inertia::render' => 'inertia',
                    'Event::dispatch' => 'events',
                    default => null,
                },
                $node instanceof Expr\FuncCall && $node->name instanceof Node\Name => match (strtolower($node->name->toString())) {
                    'inertia' => 'inertia',
                    'event' => 'events',
                    'dispatch' => 'dispatch',
                    default => null,
                },
                $node instanceof Expr\New_ && $node->class instanceof Node\Name => PhpSource::className($node->class) === 'GuzzleHttp\\Client' ? 'guzzle' : null,
                default => null,
            };
            if ($counter !== null) {
                $this->usage[$counter] = ($this->usage[$counter] ?? 0) + 1;
            }
        }
    }
}
