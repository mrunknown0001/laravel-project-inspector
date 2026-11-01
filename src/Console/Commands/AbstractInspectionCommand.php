<?php

declare(strict_types=1);

namespace LaraInspect\Console\Commands;

use LaraInspect\Console\ConsoleConfirmation;
use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Exception\InspectorException;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\Severity;
use LaraInspect\Process\AuditLog;
use LaraInspect\Reports\ConsoleReporter;
use LaraInspect\Reports\JsonReporter;
use LaraInspect\Reports\ReporterRegistry;
use LaraInspect\Reports\ReportStore;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Support\Services;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared behaviour for commands that inspect a project and render the result.
 */
abstract class AbstractInspectionCommand extends Command
{
    /** Returned when --fail-on is set and a finding meets the threshold. */
    public const EXIT_FINDINGS = 2;

    public function __construct(
        protected readonly Services $services,
    ) {
        parent::__construct();
    }

    /**
     * Analyzers this command always runs, or null to honour --only/--exclude.
     *
     * @return list<string>|null
     */
    protected function fixedAnalyzers(): ?array
    {
        return null;
    }

    /**
     * Whether this command can run analyzers that use routes (and so --use-artisan applies).
     */
    protected function usesRoutes(): bool
    {
        $fixed = $this->fixedAnalyzers();

        return $fixed === null || array_intersect($fixed, ['routes', 'security', 'architecture']) !== [];
    }

    protected function defaultFormat(): string
    {
        return ReporterRegistry::CONSOLE;
    }

    protected function pathIsRequired(): bool
    {
        return true;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            $this->pathIsRequired() ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
            'Path to the Laravel project to inspect',
        );

        if ($this->fixedAnalyzers() === null) {
            $names = implode(', ', $this->services->analyzers()->names());
            $this->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these analyzers (comma-separated or repeated): ' . $names);
            $this->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip these analyzers (comma-separated or repeated)');
        }

        if ($this->usesRoutes()) {
            $this->addOption('use-artisan', null, InputOption::VALUE_NONE, 'List routes with "php artisan route:list" (boots the project; asks for confirmation)');
        }
        if ($this->fixedAnalyzers() === null) {
            $this->addOption('run-quality-tools', null, InputOption::VALUE_OPTIONAL, 'Run code-quality tools in report-only mode (all, or a list: ' . implode(',', \LaraInspect\Analyzers\CodeQualityAnalyzer::RUNNABLE) . '); asks for confirmation', false);
        }

        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: ' . implode(', ', $this->services->reporters()->formats()), $this->defaultFormat())
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the report to this file ("-" for standard output). Must be outside the inspected project')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Skip checks that contact remote services (Packagist advisories and outdated packages)')
            ->addOption('no-save', null, InputOption::VALUE_NONE, 'Do not write any files (no saved results and no audit log)')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, sprintf('Exit with code %d if any finding is at or above this severity (critical, high, medium, low, info)', self::EXIT_FINDINGS));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            return $this->handle($input, $output, $io);
        } catch (InspectorException | \InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $format = $this->services->reporters()->normalise(self::stringOption($input, 'format') ?? $this->defaultFormat());
        $failOn = self::stringOption($input, 'fail-on');
        $threshold = $failOn !== null ? Severity::fromName($failOn) : null;

        $path = $input->getArgument('path');
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('A project path is required.');
        }

        $report = $this->inspect($path, $input, $output, $io);
        $this->deliver($report, $format, $input, $output, $io);

        return $this->exitCode($report, $threshold);
    }

    protected function inspect(string $path, InputInterface $input, OutputInterface $output, SymfonyStyle $io): InspectionReport
    {
        $services = $this->services;
        $guard = $services->guard($path);
        $noSave = (bool) $input->getOption('no-save');

        $analyzers = $services->analyzers()->select(
            $this->fixedAnalyzers() ?? self::listOption($input, 'only'),
            $this->fixedAnalyzers() === null ? self::listOption($input, 'exclude') : [],
        );

        $redactor = new SecretRedactor();
        $audit = new AuditLog($redactor);
        if (!$noSave) {
            $store = $this->store($guard->root());
            $store->ensureDirectory();
            $audit->setFile($store->auditLogPath());
        }

        $options = $services->options(
            (bool) $input->getOption('offline'),
            $input->hasOption('use-artisan') && (bool) $input->getOption('use-artisan'),
            $this->qualityTools($input),
        );
        $context = $services->createContext($guard, $options, $audit, $redactor, new ConsoleConfirmation($input, $io));
        if (!$context->project->isLaravel) {
            $reasons = $context->project->warnings !== [] ? ' ' . implode(' ', $context->project->warnings) : '';
            throw new InspectorException(sprintf('"%s" is not a Laravel application.%s', $guard->root(), $reasons));
        }

        $progress = $this->progressOutput($output);
        $report = $services->inspector($audit)->inspect(
            $context,
            $analyzers,
            static function (AnalyzerInterface $analyzer) use ($progress): void {
                $progress?->writeln(sprintf('<fg=gray>Running %s analyzer...</>', $analyzer->name()));
            },
        );

        if (!$noSave) {
            $saved = $this->store($guard->root())->write($report, new JsonReporter());
            $progress?->writeln(sprintf('<fg=gray>Results saved to %s</>', $saved));
        }

        return $report;
    }

    protected function deliver(InspectionReport $report, string $format, InputInterface $input, OutputInterface $output, SymfonyStyle $io): void
    {
        $target = self::stringOption($input, 'output');

        if ($format === ReporterRegistry::CONSOLE) {
            if ($target !== null) {
                throw new \InvalidArgumentException('--output needs a file format: use --format=markdown, html or json.');
            }
            (new ConsoleReporter())->render($report, $io);

            return;
        }

        $reporter = $this->services->reporters()->get($format);
        if ($target === '-') {
            $output->write($reporter->render($report), false, OutputInterface::OUTPUT_RAW);

            return;
        }
        if ($target === null && (bool) $input->getOption('no-save')) {
            throw new \InvalidArgumentException('--no-save with a file format needs --output (a path, or "-" for standard output).');
        }

        $path = $this->store($report->project->root)->write($report, $reporter, $target, (string) getcwd());
        $io->success(sprintf('%s report written to %s', ucfirst($format), $path));
    }

    protected function store(string $protectedRoot): ReportStore
    {
        return ReportStore::at($this->services->outputDirectory(), (string) getcwd(), $protectedRoot);
    }

    protected function exitCode(InspectionReport $report, ?Severity $threshold): int
    {
        $highest = $report->highestSeverity();

        return $threshold !== null && $highest !== null && $highest->isAtLeast($threshold)
            ? self::EXIT_FINDINGS
            : Command::SUCCESS;
    }

    /**
     * --run-quality-tools          => ['all']
     * --run-quality-tools=pint,phpstan => ['pint', 'phpstan']
     * (option absent)              => []
     *
     * @return list<string>
     */
    private function qualityTools(InputInterface $input): array
    {
        if (!$input->hasOption('run-quality-tools')) {
            return [];
        }
        $value = $input->getOption('run-quality-tools');
        if ($value === false) {
            return [];
        }
        if ($value === null || $value === '') {
            return ['all'];
        }

        return array_values(array_filter(array_map(static fn (string $t): string => strtolower(trim($t)), explode(',', (string) $value))));
    }

    /**
     * Progress goes to stderr so stdout stays clean for --output=-.
     */
    private function progressOutput(OutputInterface $output): ?OutputInterface
    {
        if ($output->isQuiet()) {
            return null;
        }

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    protected static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Accepts both --only=a,b and --only=a --only=b.
     *
     * @return list<string>
     */
    protected static function listOption(InputInterface $input, string $name): array
    {
        $values = [];
        foreach ((array) $input->getOption($name) as $value) {
            foreach (explode(',', (string) $value) as $item) {
                $item = strtolower(trim($item));
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
