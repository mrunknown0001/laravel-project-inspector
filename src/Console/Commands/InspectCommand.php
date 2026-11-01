<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'inspect',
    description: 'Run every analyzer against a Laravel project and print the report',
    aliases: ['analyze'],
)]
final class InspectCommand extends AbstractInspectionCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
            Inspects a Laravel project without modifying it and prints a report.

              <info>larainspect inspect /path/to/project</info>
              <info>larainspect inspect ./app --only=dependencies --offline</info>
              <info>larainspect inspect ./app --format=html --output=report.html</info>
              <info>larainspect inspect ./app --fail-on=high</info>   (exit code 2 in CI when HIGH+ findings exist)

            Results are saved as JSON in ./reports so they can be re-rendered later with
            <info>larainspect report</info>. Use --no-save to write nothing.
            HELP);
    }
}
