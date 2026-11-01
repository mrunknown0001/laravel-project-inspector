<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Process\CommandSpec;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Support\Arr;
use LaraInspect\Support\Evidence;

/**
 * Static analysis, code style, tests and CI.
 *
 * By default tools are only detected. With --run-quality-tools they are run
 * (after confirmation) in non-modifying modes: Pint --test, PHP CS Fixer
 * --dry-run, PHPStan and PHP_CodeSniffer in report mode. Running them still
 * executes the project's own vendor code, which is why confirmation is needed.
 */
final class CodeQualityAnalyzer implements AnalyzerInterface
{
    /** key => [label, packages, config files, vendor/bin binary or null (detect only), run arguments] */
    public const TOOLS = [
        'phpstan' => ['PHPStan', ['phpstan/phpstan'], ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'], 'phpstan', ['analyse', '--no-progress', '--error-format=json', '--no-interaction', '--memory-limit=1G']],
        'larastan' => ['Larastan', ['larastan/larastan', 'nunomaduro/larastan'], [], null, []],
        'psalm' => ['Psalm', ['vimeo/psalm'], ['psalm.xml', 'psalm.xml.dist'], null, []],
        'pint' => ['Laravel Pint', ['laravel/pint'], ['pint.json'], 'pint', ['--test', '--format=json']],
        'php-cs-fixer' => ['PHP CS Fixer', ['friendsofphp/php-cs-fixer'], ['.php-cs-fixer.php', '.php-cs-fixer.dist.php', '.php_cs', '.php_cs.dist'], 'php-cs-fixer', ['fix', '--dry-run', '--format=json', '--using-cache=no', '--no-interaction']],
        'phpcs' => ['PHP_CodeSniffer', ['squizlabs/php_codesniffer'], ['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist'], 'phpcs', ['--report=json', '-q', '--no-cache']],
        'rector' => ['Rector', ['rector/rector'], ['rector.php'], null, []],
    ];

    public const RUNNABLE = ['phpstan', 'pint', 'php-cs-fixer', 'phpcs'];

    private const TEST_FRAMEWORKS = ['pestphp/pest' => 'Pest', 'phpunit/phpunit' => 'PHPUnit'];

    private const CI_FILES = [
        '.github/workflows' => 'GitHub Actions', '.gitlab-ci.yml' => 'GitLab CI', 'bitbucket-pipelines.yml' => 'Bitbucket Pipelines',
        '.circleci/config.yml' => 'CircleCI', 'Jenkinsfile' => 'Jenkins', 'azure-pipelines.yml' => 'Azure Pipelines', '.travis.yml' => 'Travis CI',
    ];

    public const RUN_WARNING = 'This runs the project\'s own vendor/bin tool, which executes code from its dependencies and configuration '
        . '(Larastan also boots the Laravel application). Tools run in report-only modes and are not asked to change files, '
        . 'but they may write cache files (PHPStan uses its tmpDir, by default in the system temp directory).';

    public function name(): string
    {
        return 'quality';
    }

    public function title(): string
    {
        return 'Code Quality';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $composer = $context->composer();
        $files = $context->files;
        $hasPackage = static fn (array $packages): ?string => array_values(array_filter($packages, static fn (string $p): bool => $composer->lockedPackage($p) !== null || isset($composer->requireDev()[$p]) || isset($composer->require()[$p])))[0] ?? null;

        $installed = [];
        $rows = [];
        foreach (self::TOOLS as $key => [$label, $packages, $configs, $binary]) {
            $package = $hasPackage($packages);
            $config = array_values(array_filter($configs, static fn (string $c): bool => $files->isFile($c)))[0] ?? null;
            $binaryPresent = $binary !== null && $files->isFile('vendor/bin/' . $binary);
            if ($package !== null) {
                $installed[$key] = ['label' => $label, 'binary' => $binaryPresent];
            }
            $rows[] = [$label, $package ?? 'not required', $config ?? ($configs === [] ? 'n/a' : 'none'), $binary === null ? 'n/a' : ($binaryPresent ? 'yes' : 'no')];
        }

        $framework = null;
        foreach (self::TEST_FRAMEWORKS as $package => $label) {
            if ($hasPackage([$package]) !== null) {
                $framework = $label;
                break;
            }
        }
        $testFiles = array_values(array_filter($files->findFiles('tests', ['php']), static fn (string $f): bool => str_ends_with($f, 'Test.php') || preg_match('#tests/(Feature|Unit|Browser)/#', $f) === 1));
        $feature = count(array_filter($testFiles, static fn (string $f): bool => str_contains($f, '/Feature/')));
        $unit = count(array_filter($testFiles, static fn (string $f): bool => str_contains($f, '/Unit/')));

        [$ci, $ciTools] = $this->ci($context);

        $facts = [
            new Fact('Test framework', $framework ?? 'None detected', Source::Detected, 'composer.json'),
            new Fact('Test files', sprintf('%d (%d feature, %d unit)', count($testFiles), $feature, $unit), Source::Detected, 'tests/'),
            new Fact('phpunit.xml', $files->isFile('phpunit.xml') || $files->isFile('phpunit.xml.dist') ? 'Present' : 'Missing', Source::Detected, null),
            new Fact('Continuous integration', $ci === [] ? 'None detected' : implode(', ', $ci), Source::Detected, null),
            new Fact('Checks referenced in CI', $ciTools === [] ? 'None' : implode(', ', $ciTools), Source::Detected, 'CI configuration'),
        ];

        $findings = $this->absenceFindings($installed, $framework, $testFiles, $ci);
        $notes = [];
        $runRows = [];

        $requested = $context->options->qualityTools;
        if ($requested === []) {
            $notes[] = 'Tools were detected but not run. Use --run-quality-tools (or --run-quality-tools=phpstan,pint) to run them; you will be asked to confirm.';
        } else {
            $selected = in_array('all', $requested, true) ? self::RUNNABLE : array_values(array_intersect(self::RUNNABLE, $requested));
            foreach (array_diff($requested, [...self::RUNNABLE, 'all']) as $unknown) {
                $notes[] = sprintf('Unknown quality tool "%s"; runnable tools are: %s.', $unknown, implode(', ', self::RUNNABLE));
            }
            foreach ($selected as $key) {
                if (!($installed[$key]['binary'] ?? false)) {
                    if (!in_array('all', $requested, true)) {
                        $notes[] = sprintf('%s was not run: vendor/bin/%s is not installed.', self::TOOLS[$key][0], self::TOOLS[$key][3]);
                    }
                    continue;
                }
                [$row, $finding, $note] = $this->runTool($context, $key);
                $runRows[] = $row;
                if ($finding !== null) {
                    $findings[] = $finding;
                }
                if ($note !== null) {
                    $notes[] = $note;
                }
            }
        }

        $tables = [new Table('Quality tools', ['Tool', 'Package', 'Config', 'Installed binary'], $rows)];
        if ($runRows !== []) {
            $tables[] = new Table('Tool results', ['Tool', 'Outcome', 'Result'], $runRows);
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: $findings,
            notes: $notes,
            data: [
                'tools' => array_map(static fn (array $t): string => $t['label'], $installed),
                'test_framework' => $framework,
                'test_files' => count($testFiles),
                'ci' => $ci,
                'ci_tools' => $ciTools,
                'runs' => $runRows,
            ],
        );
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function ci(InspectionContext $context): array
    {
        $systems = [];
        $contents = '';
        foreach (self::CI_FILES as $path => $label) {
            $paths = $context->files->isDirectory($path) ? $context->files->findFiles($path, ['yml', 'yaml']) : ($context->files->isFile($path) ? [$path] : []);
            if ($paths === []) {
                continue;
            }
            $systems[] = $label;
            foreach ($paths as $file) {
                $contents .= "\n" . ($context->files->readIfExists($file) ?? '');
            }
        }

        $tools = [];
        foreach (['phpunit' => 'PHPUnit', 'pest' => 'Pest', 'phpstan' => 'PHPStan', 'larastan' => 'Larastan', 'pint' => 'Pint', 'php-cs-fixer' => 'PHP CS Fixer', 'phpcs' => 'PHP_CodeSniffer', 'psalm' => 'Psalm', 'rector' => 'Rector', 'composer audit' => 'composer audit', 'artisan test' => 'artisan test'] as $needle => $label) {
            if (stripos($contents, $needle) !== false) {
                $tools[] = $label;
            }
        }

        return [$systems, $tools];
    }

    /**
     * @param array<string, array{label: string, binary: bool}> $installed
     * @param list<string>                                        $testFiles
     * @param list<string>                                        $ci
     * @return list<Finding>
     */
    private function absenceFindings(array $installed, ?string $framework, array $testFiles, array $ci): array
    {
        $findings = [];
        if ($testFiles === []) {
            $findings[] = new Finding(
                'quality.no-tests',
                'No automated tests found',
                Severity::Low,
                Confidence::Confirmed,
                'tests/',
                'Without tests, upgrades (including security updates of Laravel and dependencies) cannot be verified quickly, so they tend to be postponed.',
                sprintf('No *Test.php files under tests/%s.', $framework !== null ? sprintf(' (%s is installed)', $framework) : ''),
                'Start with feature tests for the most important routes (php artisan make:test), and run them in CI.',
            );
        }
        if (array_intersect_key($installed, array_flip(['phpstan', 'larastan', 'psalm'])) === []) {
            $findings[] = new Finding(
                'quality.no-static-analysis',
                'No static analysis tool is installed',
                Severity::Info,
                Confidence::Confirmed,
                'composer.json',
                'Static analysis finds type errors and dead code before they reach production, and makes refactoring safer.',
                'None of phpstan/phpstan, larastan/larastan or vimeo/psalm is required.',
                'Add Larastan: composer require --dev larastan/larastan, then start at a low level and raise it over time.',
            );
        } elseif (isset($installed['phpstan']) && !isset($installed['larastan'])) {
            $findings[] = new Finding(
                'quality.phpstan-without-larastan',
                'PHPStan is used without Larastan',
                Severity::Info,
                Confidence::Potential,
                'composer.json',
                'Plain PHPStan does not understand Laravel facades, Eloquent magic properties or container resolution, which leads to false positives or ignored errors.',
                'phpstan/phpstan is required; larastan/larastan is not.',
                'Add larastan/larastan and include its extension in the PHPStan configuration.',
            );
        }
        if (array_intersect_key($installed, array_flip(['pint', 'php-cs-fixer', 'phpcs'])) === []) {
            $findings[] = new Finding(
                'quality.no-code-style',
                'No code style tool is installed',
                Severity::Info,
                Confidence::Confirmed,
                'composer.json',
                'A formatter keeps diffs focused on behaviour and avoids style discussions in reviews.',
                'None of laravel/pint, friendsofphp/php-cs-fixer or squizlabs/php_codesniffer is required.',
                'Add Laravel Pint (composer require --dev laravel/pint) and run it in CI with --test.',
            );
        }
        if ($ci === []) {
            $findings[] = new Finding(
                'quality.no-ci',
                'No continuous integration configuration found',
                Severity::Info,
                Confidence::Potential,
                'project root',
                'Without CI, tests and checks depend on each developer remembering to run them. CI may be configured outside the repository (e.g. in a hosting platform).',
                sprintf('None of: %s.', implode(', ', array_keys(self::CI_FILES))),
                'Add a pipeline that runs composer install, the test suite, static analysis, the formatter in check mode and composer audit.',
            );
        }

        return $findings;
    }

    /**
     * @return array{0: list<string>, 1: Finding|null, 2: string|null}
     */
    private function runTool(InspectionContext $context, string $key): array
    {
        [$label, , , $binary, $arguments] = self::TOOLS[$key];
        $result = $context->processes->run(new CommandSpec(
            'php',
            ['vendor/bin/' . $binary, ...$arguments],
            sprintf('Run %s in report-only mode', $label),
            $context->root(),
            max(300, $context->options->processTimeout),
            sideEffects: true,
            warning: self::RUN_WARNING,
        ));
        if (!$result->ran()) {
            return [[$label, $result->outcome, $result->failureReason()], null, sprintf('%s was not run: %s.', $label, $result->failureReason())];
        }

        $json = self::json($result);
        [$issues, $summary, $evidence] = match ($key) {
            'phpstan' => $this->phpstan($json),
            'pint', 'php-cs-fixer' => $this->formatter($json, $key),
            'phpcs' => $this->phpcs($json),
            default => [null, '', []],
        };
        if ($issues === null) {
            $summary = $result->exitCode === 0 ? 'Passed' : sprintf('Exited with code %d; output could not be interpreted', $result->exitCode ?? -1);
            $issues = $result->exitCode === 0 ? 0 : null;
        }
        $row = [$label, sprintf('%s (exit %d)', $result->outcome, $result->exitCode ?? -1), $summary];

        if ($issues === null || $issues === 0) {
            return [$row, null, null];
        }

        $finding = new Finding(
            'quality.' . $key . '-issues',
            sprintf('%s: %s', $label, $summary),
            $key === 'phpstan' ? Severity::Low : Severity::Info,
            Confidence::Confirmed,
            $key === 'phpstan' ? 'source code' : 'code style',
            $key === 'phpstan'
                ? 'PHPStan reported errors at the project\'s configured level. Some may be real bugs (wrong types, undefined methods), others false positives to baseline.'
                : sprintf('%s reports files that do not match the configured code style.', $label),
            Evidence::list($evidence),
            $key === 'phpstan'
                ? 'Fix the reported errors, or record existing ones in a baseline (phpstan analyse --generate-baseline) and fail CI only on new errors.'
                : sprintf('Run %s without the check flag in the project to apply the formatting, and add the check to CI.', $label),
        );

        return [$row, $finding, null];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{0: int|null, 1: string, 2: list<string>}
     */
    private function phpstan(?array $json): array
    {
        if ($json === null) {
            return [null, '', []];
        }
        $totals = Arr::array($json, 'totals');
        $count = Arr::int($totals, 'file_errors') + Arr::int($totals, 'errors');
        $evidence = [];
        foreach (Arr::array($json, 'files') as $file => $info) {
            if (is_array($info)) {
                foreach (Arr::listOfArrays($info, 'messages') as $message) {
                    $evidence[] = sprintf('%s:%d %s', self::relative((string) $file), Arr::int($message, 'line'), Arr::string($message, 'message'));
                }
            }
        }

        return [$count, $count === 0 ? 'No errors' : Evidence::plural($count, 'error'), $evidence];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{0: int|null, 1: string, 2: list<string>}
     */
    private function formatter(?array $json, string $key): array
    {
        if ($json === null) {
            return [null, '', []];
        }
        $files = Arr::listOfArrays($json, 'files');
        $names = array_map(static fn (array $f): string => self::relative(Arr::string($f, $key === 'pint' ? 'path' : 'name') ?: Arr::string($f, 'name')), $files);

        return [count($files), count($files) === 0 ? 'All files match' : Evidence::plural(count($files), 'file needs', 'files need') . ' formatting', $names];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{0: int|null, 1: string, 2: list<string>}
     */
    private function phpcs(?array $json): array
    {
        if ($json === null) {
            return [null, '', []];
        }
        $totals = Arr::array($json, 'totals');
        $errors = Arr::int($totals, 'errors');
        $warnings = Arr::int($totals, 'warnings');
        $evidence = [];
        foreach (Arr::array($json, 'files') as $file => $info) {
            if (is_array($info) && (Arr::int($info, 'errors') + Arr::int($info, 'warnings')) > 0) {
                $evidence[] = sprintf('%s: %d errors, %d warnings', self::relative((string) $file), Arr::int($info, 'errors'), Arr::int($info, 'warnings'));
            }
        }

        return [$errors + $warnings, sprintf('%s, %s', Evidence::plural($errors, 'error'), Evidence::plural($warnings, 'warning')), $evidence];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(ProcessResult $result): ?array
    {
        $start = strpos($result->output, '{');
        if ($start === false) {
            return null;
        }
        try {
            $decoded = json_decode(substr($result->output, $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    private static function relative(string $path): string
    {
        return (string) preg_replace('#^.*?/(app|src|config|routes|database|tests|resources)/#', '$1/', str_replace('\\', '/', $path));
    }
}
