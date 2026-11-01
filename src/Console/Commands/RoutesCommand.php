<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'routes',
    description: 'List routes with their middleware, controllers and access control (read statically by default)',
)]
final class RoutesCommand extends AbstractInspectionCommand
{
    protected function fixedAnalyzers(): array
    {
        return ['routes'];
    }
}
