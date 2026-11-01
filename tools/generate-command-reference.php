<?php

declare(strict_types=1);

/*
 * Regenerates docs/commands.md from the commands' own definitions, so the
 * reference cannot drift from the code. Run: composer docs
 */

use LaraInspect\Console\Application;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Input\InputOption;

require dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application(Services::fromBasePath(dirname(__DIR__)));
$global = array_keys($application->getDefinition()->getOptions());

$out = [
    '# Command reference',
    '',
    '_Generated from the command definitions by `composer docs`; do not edit by hand._',
    '',
    'Global options available on every command: `--help`, `--quiet`, `--silent`, `--verbose` (`-v`, `-vv`, `-vvv`), `--version`, `--ansi` / `--no-ansi`, `--no-interaction` (`-n`).',
    '',
    '## Exit codes',
    '',
    '| Code | Meaning |',
    '|---:|---|',
    '| 0 | Success. |',
    '| 1 | Error: invalid input, not a Laravel project, unreadable path, refused output location, AI provider not configured or every AI task failed. |',
    '| 2 | `--fail-on` was given and at least one finding is at or above that severity. |',
    '',
];

// all() also lists every alias as its own key; keep each command once, under its real name.
$commands = array_filter(
    $application->all(),
    static fn ($c, string $key): bool => $key === $c->getName() && !in_array($c->getName(), ['help', 'list', 'completion', '_complete'], true),
    ARRAY_FILTER_USE_BOTH,
);
$md = static fn (string $text): string => str_replace(['|', '<', '>'], ['\\|', '&lt;', '&gt;'], $text);
ksort($commands);
$out[] = '## Commands';
$out[] = '';
foreach ($commands as $name => $command) {
    $out[] = sprintf('- [`%s`](#%s) - %s', $name, str_replace(':', '', $name), $md($command->getDescription()));
}
$out[] = '';

foreach ($commands as $name => $command) {
    $out[] = '## ' . $name;
    $out[] = '';
    $out[] = $md($command->getDescription()) . '.';
    $out[] = '';
    if ($command->getAliases() !== []) {
        $out[] = 'Aliases: ' . implode(', ', array_map(static fn (string $a): string => '`' . $a . '`', $command->getAliases()));
        $out[] = '';
    }
    $out[] = '```text';
    $out[] = 'larainspect ' . $command->getSynopsis(true);
    $out[] = '```';
    $out[] = '';

    $definition = $command->getDefinition();
    if ($definition->getArguments() !== []) {
        $out[] = '| Argument | Description |';
        $out[] = '|---|---|';
        foreach ($definition->getArguments() as $argument) {
            $out[] = sprintf('| `%s`%s | %s |', $argument->getName(), $argument->isRequired() ? '' : ' (optional)', $md($argument->getDescription()));
        }
        $out[] = '';
    }

    $options = array_filter($definition->getOptions(), static fn (InputOption $o): bool => !in_array($o->getName(), $global, true));
    if ($options !== []) {
        $out[] = '| Option | Description | Default |';
        $out[] = '|---|---|---|';
        foreach ($options as $option) {
            $label = '--' . $option->getName() . ($option->getShortcut() !== null ? ', -' . $option->getShortcut() : '');
            if ($option->isValueRequired()) {
                $label .= '=VALUE';
            } elseif ($option->isValueOptional()) {
                $label .= '[=VALUE]';
            }
            $default = $option->getDefault();
            $out[] = sprintf(
                '| `%s`%s | %s | %s |',
                $label,
                $option->isArray() ? ' (repeatable)' : '',
                $md($option->getDescription()),
                is_string($default) && $default !== '' ? '`' . $default . '`' : '',
            );
        }
        $out[] = '';
    }

    $help = trim(strip_tags($command->getProcessedHelp()));
    if ($help !== '' && $help !== $command->getDescription()) {
        $out[] = '```text';
        $out[] = $help;
        $out[] = '```';
        $out[] = '';
    }
}

file_put_contents(dirname(__DIR__) . '/docs/commands.md', implode("\n", $out));
echo "docs/commands.md written\n";
