<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'dependencies',
    description: 'Analyze Composer dependencies: lock drift, PHP compatibility, advisories, abandoned and outdated packages',
)]
final class DependenciesCommand extends AbstractInspectionCommand
{
    protected function fixedAnalyzers(): array
    {
        return ['dependencies'];
    }
}
