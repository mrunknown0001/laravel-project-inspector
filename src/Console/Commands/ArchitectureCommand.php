<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'architecture',
    description: 'Summarise the application architecture: components, patterns, frontend stack and integrations',
)]
final class ArchitectureCommand extends AbstractInspectionCommand
{
    protected function fixedAnalyzers(): array
    {
        return ['architecture'];
    }
}
