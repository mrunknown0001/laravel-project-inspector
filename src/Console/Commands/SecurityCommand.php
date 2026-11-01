<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'security',
    description: 'Run rule-based security checks: configuration, sensitive files, secrets, dangerous code, routes, dependencies',
)]
final class SecurityCommand extends AbstractInspectionCommand
{
    protected function fixedAnalyzers(): array
    {
        return ['security'];
    }
}
