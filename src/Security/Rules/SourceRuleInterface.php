<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Finding;
use PhpParser\Node;

/**
 * A security check that inspects source files one at a time.
 *
 * The security analyzer reads and parses each file once and offers it to
 * every source rule, so adding a rule does not add another pass over the code.
 */
interface SourceRuleInterface
{
    public function reset(): void;

    /**
     * @param string                $file  Project-relative path.
     * @param list<Node\Stmt>|null  $stmts Parsed PHP (names resolved), or null for non-PHP / unparsable files.
     */
    public function visit(string $file, string $contents, ?array $stmts): void;

    /**
     * @return list<Finding>
     */
    public function findings(InspectionContext $context): array;
}
