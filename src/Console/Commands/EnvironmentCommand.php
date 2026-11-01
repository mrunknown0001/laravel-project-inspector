<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'environment',
    description: 'Show PHP, Laravel, tooling, frontend and driver information for a project',
)]
final class EnvironmentCommand extends AbstractInspectionCommand
{
    protected function fixedAnalyzers(): array
    {
        return ['environment'];
    }
}
