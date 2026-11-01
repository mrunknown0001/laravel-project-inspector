<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\EnvironmentAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class EnvironmentAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private function runner(): FakeProcessRunner
    {
        return (new FakeProcessRunner())
            ->respond('composer --version', ProcessResult::completed(0, "Composer version 2.10.3 2026-08-27 13:34:23\nPHP version 8.3.32\n"))
            ->respond('node --version', ProcessResult::completed(0, "v22.23.3\n"))
            ->respond('npm --version', ProcessResult::completed(1, '', 'npm broke'));
    }

    private function analyze(string $fixture, ?FakeProcessRunner $runner = null): AnalyzerResult
    {
        return (new EnvironmentAnalyzer())->analyze(Fixtures::context(Fixtures::project($fixture), $runner ?? $this->runner()));
    }

    private static function fact(AnalyzerResult $result, string $label): Fact
    {
        foreach ($result->facts as $fact) {
            if ($fact->label === $label) {
                return $fact;
            }
        }
        self::fail(sprintf('Fact "%s" not found.', $label));
    }

    public function testReportsVersionsAndEnvironmentForLaravel8(): void
    {
        $result = $this->analyze('laravel-8');

        self::assertSame('8.83.27', self::fact($result, 'Laravel version')->value);
        self::assertStringStartsWith('End of life', self::fact($result, 'Laravel support status')->value);
        self::assertSame('^7.3|^8.0', self::fact($result, 'PHP requirement')->value);
        self::assertSame(PHP_VERSION, self::fact($result, 'PHP running the inspector')->value);
        self::assertSame('2.10.3', self::fact($result, 'Composer version')->value);
        self::assertSame('22.23.3', self::fact($result, 'Node.js version')->value);
        self::assertSame('Not installed', self::fact($result, 'npm version')->value, 'A failing tool is reported as unavailable.');
        self::assertSame('Not installed', self::fact($result, 'pnpm version')->value);
        self::assertSame('production', self::fact($result, 'Application environment')->value);
        self::assertSame('true', self::fact($result, 'Debug mode')->value);
        self::assertSame('MySQL', self::fact($result, 'Database technology')->value);
        self::assertSame('Laravel Mix', self::fact($result, 'Frontend build tool')->value);
        self::assertSame('npm', self::fact($result, 'Frontend package manager')->value);
        self::assertSame('Vue', self::fact($result, 'Frontend technologies')->value);

        $drivers = $result->data['drivers'];
        self::assertSame(['database' => 'mysql', 'cache' => 'redis', 'queue' => 'redis', 'session' => 'redis', 'log' => 'stack', 'filesystem' => 'local', 'mail' => 'smtp', 'broadcast' => 'null'], $drivers);
    }

    public function testFlagsEndOfLifeLaravel(): void
    {
        $finding = $this->analyze('laravel-8')->findings[0];

        self::assertSame('env.laravel-end-of-life', $finding->id);
        self::assertSame(Severity::Medium, $finding->severity);
        self::assertStringContainsString('2023-01-24', $finding->evidence);
    }

    public function testSecurityOnlyLaravelIsLowSeverity(): void
    {
        $ids = array_map(static fn ($f) => $f->id . ':' . $f->severity->value, $this->analyze('laravel-12')->findings);

        self::assertContains('env.laravel-security-only:LOW', $ids);
    }

    public function testValuesFromEnvExampleAreMarkedInferred(): void
    {
        $result = $this->analyze('laravel-10');

        $environment = self::fact($result, 'Application environment');
        self::assertSame('local', $environment->value);
        self::assertSame(Source::Inferred, $environment->source);
        self::assertSame('PostgreSQL', self::fact($result, 'Database technology')->value);
        self::assertSame('Vite', self::fact($result, 'Frontend build tool')->value);
        self::assertSame('pnpm', self::fact($result, 'Frontend package manager')->value);
        self::assertSame('Vue, Inertia (Vue), Tailwind CSS', self::fact($result, 'Frontend technologies')->value);
        self::assertStringContainsString('.env.example', implode(' ', $result->notes));
    }

    public function testModernSkeletonUsesFrameworkDefaults(): void
    {
        $result = $this->analyze('laravel-11');

        self::assertStringContainsString('Laravel 11+', self::fact($result, 'Project layout')->value);
        self::assertSame('8.2.12', self::fact($result, 'PHP platform override')->value);
        self::assertSame('SQLite', self::fact($result, 'Database technology')->value);
        self::assertSame('Livewire, Alpine.js, Tailwind CSS', self::fact($result, 'Frontend technologies')->value);
        self::assertSame('log', $result->data['drivers']['mail']);
    }

    public function testToolsRunOutsideTheProjectWithoutSideEffects(): void
    {
        $runner = $this->runner();
        $this->analyze('laravel-8', $runner);

        self::assertNotEmpty($runner->commands);
        foreach ($runner->commands as $command) {
            self::assertSame(sys_get_temp_dir(), $command->workingDirectory);
            self::assertFalse($command->sideEffects);
            self::assertFalse($command->network);
        }
    }

    public function testFlagsMissingPhpRequirementAndRuntimeMismatch(): void
    {
        $missing = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^12.0"}}',
        ]);
        $mismatch = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"php": "^7.4", "laravel/framework": "^8.0"}}',
        ]);

        $ids = static fn (string $root): array => array_map(
            static fn ($f) => $f->id,
            (new EnvironmentAnalyzer())->analyze(Fixtures::context($root, new FakeProcessRunner()))->findings,
        );

        self::assertContains('env.php-requirement-missing', $ids($missing));
        self::assertContains('env.runtime-php-mismatch', $ids($mismatch));
    }
}
