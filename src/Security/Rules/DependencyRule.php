<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Composer\ComposerAudit;
use LaraInspect\Composer\ComposerConfigChecks;
use LaraInspect\Core\InspectionContext;

/**
 * Packages with published security advisories and insecure Composer settings.
 *
 * Shares its checks with the dependency analyzer; when both run, the
 * inspector keeps each finding once, under the analyzer that ran first.
 */
final class DependencyRule implements ProjectRuleInterface
{
    public function check(InspectionContext $context): array
    {
        if (!$context->composer()->hasManifest()) {
            return [];
        }

        return [
            ...ComposerAudit::advisoryFindings($context->composer(), $context->composerAudit()->advisories),
            ...ComposerConfigChecks::findings($context->composer()),
        ];
    }
}
