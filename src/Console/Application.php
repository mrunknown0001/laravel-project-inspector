<?php

declare(strict_types=1);

namespace LaraInspect\Console;

use LaraInspect\Console\Commands\AiCommand;
use LaraInspect\Console\Commands\AiModelsCommand;
use LaraInspect\Console\Commands\AiProvidersCommand;
use LaraInspect\Console\Commands\ArchitectureCommand;
use LaraInspect\Console\Commands\ConfigInitCommand;
use LaraInspect\Console\Commands\ConfigShowCommand;
use LaraInspect\Console\Commands\ConfigTestCommand;
use LaraInspect\Console\Commands\DependenciesCommand;
use LaraInspect\Console\Commands\EnvironmentCommand;
use LaraInspect\Console\Commands\InspectCommand;
use LaraInspect\Console\Commands\ReportCommand;
use LaraInspect\Console\Commands\RoutesCommand;
use LaraInspect\Console\Commands\SecurityCommand;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct(Services $services)
    {
        parent::__construct('Laravel Project Inspector', $services->version());

        $this->addCommands([
            new InspectCommand($services),
            new EnvironmentCommand($services),
            new DependenciesCommand($services),
            new RoutesCommand($services),
            new SecurityCommand($services),
            new ArchitectureCommand($services),
            new ReportCommand($services),
            new AiCommand($services),
            new AiProvidersCommand($services),
            new AiModelsCommand($services),
            new ConfigShowCommand($services),
            new ConfigInitCommand($services),
            new ConfigTestCommand($services),
        ]);
    }

    public function getHelp(): string
    {
        return parent::getHelp() . "\n\n"
            . 'Read-only inspection of Laravel projects. The inspected project is never modified, '
            . 'and project code is never executed unless you explicitly opt in.';
    }
}
