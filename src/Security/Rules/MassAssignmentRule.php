<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Support\Evidence;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Eloquent models with mass-assignment protection switched off.
 */
final class MassAssignmentRule implements SourceRuleInterface
{
    private const MODEL_PARENTS = ['Model', 'Authenticatable', 'User', 'Pivot', 'MorphPivot'];

    /** @var list<string> */
    private array $unguardedModels = [];

    /** @var list<string> */
    private array $globalUnguard = [];

    public function reset(): void
    {
        $this->unguardedModels = [];
        $this->globalUnguard = [];
    }

    public function visit(string $file, string $contents, ?array $stmts): void
    {
        $path = SourceFile::path($file);
        if ($stmts === null) {
            return;
        }
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($stmts, Stmt\Class_::class) as $class) {
            if (!$this->isModel($class, $path)) {
                continue;
            }
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === 'guarded' && $prop->default instanceof Expr\Array_ && $prop->default->items === []) {
                        $this->unguardedModels[] = Evidence::location($file, $prop->getStartLine(), sprintf('%s: protected $guarded = [];', $class->name?->toString() ?? 'class'));
                    }
                }
            }
        }

        foreach ($finder->findInstanceOf($stmts, Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name && in_array(PhpSource::shortName($call->class), ['Model', 'Eloquent'], true)
                && $call->name instanceof Node\Identifier && $call->name->toString() === 'unguard') {
                $this->globalUnguard[] = Evidence::location($file, $call->getStartLine(), 'Model::unguard()');
            }
        }
    }

    public function findings(InspectionContext $context): array
    {
        $findings = [];
        if ($this->globalUnguard !== []) {
            $findings[] = new Finding(
                'sec.model-unguard',
                'Mass-assignment protection is disabled globally',
                Severity::Medium,
                Confidence::Potential,
                'source code',
                'Model::unguard() turns off $fillable/$guarded for every model. Any code that passes request input to create() or update() can then set any column. It is safe only inside seeders or tests.',
                Evidence::list($this->globalUnguard),
                'Remove Model::unguard() from application code (keep it in seeders), and use $fillable on models.',
            );
        }
        if ($this->unguardedModels !== []) {
            $findings[] = new Finding(
                'sec.model-guarded-empty',
                Evidence::plural(count($this->unguardedModels), 'model disables', 'models disable') . ' mass-assignment protection',
                Severity::Low,
                Confidence::Potential,
                'app/Models',
                'With $guarded = [] every attribute can be mass-assigned. This is safe only if the model never receives unfiltered input (e.g. $request->all()).',
                Evidence::list($this->unguardedModels),
                'Declare $fillable with the attributes users may set, or make sure only validated data reaches these models.',
            );
        }

        return $findings;
    }

    private function isModel(Stmt\Class_ $class, string $file): bool
    {
        if ($class->extends !== null) {
            $parent = PhpSource::className($class->extends) ?? '';
            $base = substr((string) strrchr('\\' . $parent, '\\'), 1);
            if (in_array($base, self::MODEL_PARENTS, true) && (str_contains($parent, 'Eloquent') || str_contains($parent, 'Auth') || str_contains($parent, 'Model') || str_starts_with($file, 'app/Models/'))) {
                return true;
            }
        }

        return str_starts_with($file, 'app/Models/');
    }
}
