<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Finding;

/**
 * A security check that looks at the project as a whole (configuration, files, routes).
 */
interface ProjectRuleInterface
{
    /**
     * @return list<Finding>
     */
    public function check(InspectionContext $context): array;
}
