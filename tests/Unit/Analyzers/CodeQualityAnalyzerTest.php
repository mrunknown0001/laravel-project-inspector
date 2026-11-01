<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\CodeQualityAnalyzer;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Core\InspectionOptions;
use LaraInspect\Core\ProjectDetector;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Security\SecretRedactor;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class CodeQualityAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @param list<string> $tools
     */
    private function analyze(string $root, array $tools = [], ?FakeProcessRunner $runner = null): AnalyzerResult
    {
        $files = Fixtures::reader($root);
        $context = new InspectionContext((new ProjectDetector())->detect($files), $files, $runner ?? new FakeProcessRunner(), new SecretRedactor(), new InspectionOptions(qualityTools: $tools));

        return (new CodeQualityAnalyzer())->analyze($context);
    }

    private function projectWithTools(): string
    {
        return $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^11.0"}, "require-dev": {"larastan/larastan": "^3.0", "phpstan/phpstan": "^2.0", "laravel/pint": "^1.0", "pestphp/pest": "^3.0"}}',
            'phpstan.neon' => 'parameters: {level: 5}',
            'vendor/bin/phpstan' => '#!/usr/bin/env php',
            'vendor/bin/pint' => '#!/usr/bin/env php',
            'tests/Feature/ExampleTest.php' => '<?php',
            'tests/Unit/ExampleTest.php' => '<?php',
            '.github/workflows/tests.yml' => "steps:\n  - run: vendor/bin/pest\n  - run: vendor/bin/phpstan analyse\n  - run: composer audit\n",
        ]);
    }

    /**
     * @return list<string>
     */
    private static function ids(AnalyzerResult $result): array
    {
        return array_map(static fn ($f) => $f->id, $result->findings);
    }

    public function testDetectsToolsTestsAndCiWithoutRunningAnything(): void
    {
        $runner = new FakeProcessRunner();
        $result = $this->analyze($this->projectWithTools(), [], $runner);

        self::assertSame([], $runner->commands);
        self::assertSame(['phpstan' => 'PHPStan', 'larastan' => 'Larastan', 'pint' => 'Laravel Pint'], $result->data['tools']);
        self::assertSame('Pest', $result->data['test_framework']);
        self::assertSame(2, $result->data['test_files']);
        self::assertSame(['GitHub Actions'], $result->data['ci']);
        self::assertSame(['Pest', 'PHPStan', 'composer audit'], $result->data['ci_tools']);
        self::assertSame([], self::ids($result));
        self::assertStringContainsString('--run-quality-tools', implode(' ', $result->notes));
    }

    public function testReportsMissingTestsToolingAndCi(): void
    {
        $ids = self::ids($this->analyze(Fixtures::project('laravel-11')));

        self::assertSame(['quality.no-tests', 'quality.no-ci'], array_values(array_intersect($ids, ['quality.no-tests', 'quality.no-ci'])));
        self::assertNotContains('quality.no-code-style', $ids, 'laravel/pint is installed in the fixture.');
        self::assertContains('quality.no-static-analysis', $ids);
    }

    public function testRunsRequestedToolsInReportOnlyModeAndParsesResults(): void
    {
        $phpstan = json_encode(['totals' => ['errors' => 0, 'file_errors' => 2], 'files' => [
            '/srv/app/app/Models/User.php' => ['errors' => 2, 'messages' => [['message' => 'Undefined property $foo.', 'line' => 12], ['message' => 'Missing return type.', 'line' => 20]]],
        ], 'errors' => []], JSON_THROW_ON_ERROR);
        $pint = json_encode(['result' => 'fail', 'files' => [['path' => '/srv/app/app/Http/Kernel.php', 'fixers' => ['braces']]]], JSON_THROW_ON_ERROR);
        $runner = (new FakeProcessRunner())->respond('php vendor/bin/phpstan', ProcessResult::completed(1, $phpstan))
            ->respond('php vendor/bin/pint', ProcessResult::completed(1, $pint));

        $result = $this->analyze($this->projectWithTools(), ['all'], $runner);

        $commands = [];
        foreach ($runner->commands as $command) {
            $commands[] = implode(' ', $command->arguments);
            self::assertTrue($command->sideEffects, 'Running project tools needs confirmation.');
            self::assertNotNull($command->warning);
        }
        self::assertSame([
            'vendor/bin/phpstan analyse --no-progress --error-format=json --no-interaction --memory-limit=1G',
            'vendor/bin/pint --test --format=json',
        ], $commands, 'Only installed tools run; Pint only in --test mode.');

        $findings = [];
        foreach ($result->findings as $finding) {
            $findings[$finding->id] = $finding;
        }
        self::assertSame('PHPStan: 2 errors', $findings['quality.phpstan-issues']->title);
        self::assertStringContainsString('app/Models/User.php:12 Undefined property $foo.', $findings['quality.phpstan-issues']->evidence);
        self::assertSame('Laravel Pint: 1 file needs formatting', $findings['quality.pint-issues']->title);
        self::assertStringContainsString('app/Http/Kernel.php', $findings['quality.pint-issues']->evidence);
    }

    public function testRefusedRunsAndUnknownToolsAreNoted(): void
    {
        $runner = (new FakeProcessRunner())->respond('php vendor/bin/phpstan', ProcessResult::refused('the command may have side effects and was not confirmed'));
        $result = $this->analyze($this->projectWithTools(), ['phpstan', 'phpcs', 'eslint'], $runner);
        $notes = implode("\n", $result->notes);

        self::assertStringContainsString('PHPStan was not run: the command was not permitted', $notes);
        self::assertStringContainsString('PHP_CodeSniffer was not run: vendor/bin/phpcs is not installed', $notes);
        self::assertStringContainsString('Unknown quality tool "eslint"', $notes);
        self::assertNotContains('quality.phpstan-issues', self::ids($result));
    }
}
