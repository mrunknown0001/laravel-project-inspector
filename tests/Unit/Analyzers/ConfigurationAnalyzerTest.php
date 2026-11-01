<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Analyzers;

use LaraInspect\Analyzers\ConfigurationAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Severity;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConfigurationAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private function analyze(string $root): AnalyzerResult
    {
        return (new ConfigurationAnalyzer())->analyze(Fixtures::context($root));
    }

    /**
     * @return array<string, array{0: string, 1: string}> "Area/Setting" => [value, source]
     */
    private static function overview(AnalyzerResult $result): array
    {
        $map = [];
        foreach ($result->tables[0]->rows as [$area, $setting, $value, $source]) {
            $map[$area . '/' . $setting] = [$value, $source];
        }

        return $map;
    }

    /**
     * @return array<string, \LaraInspect\Model\Finding>
     */
    private static function findings(AnalyzerResult $result): array
    {
        $map = [];
        foreach ($result->findings as $finding) {
            $map[$finding->id] = $finding;
        }

        return $map;
    }

    public function testValuesAreLabelledDetectedOrInferred(): void
    {
        $overview = self::overview($this->analyze(Fixtures::project('laravel-8')));

        self::assertSame(['production', 'detected'], $overview['Application/Environment']);
        self::assertSame(['redis', 'detected'], $overview['Session/Driver']);
        self::assertSame(['stack', 'inferred'], $overview['Logging/Default channel']);
        self::assertSame(['UTC', 'detected'], $overview['Application/Timezone']);
        self::assertSame('Set', $overview['Application/Application key'][0]);
    }

    public function testSlimSkeletonUsesFrameworkDefaultsIncludingCors(): void
    {
        $result = $this->analyze(Fixtures::project('laravel-11'));
        $overview = self::overview($result);

        self::assertSame(['true', 'inferred'], $overview['Session/HTTP-only cookie']);
        self::assertSame(['lax', 'inferred'], $overview['Session/SameSite']);
        self::assertSame(['12', 'inferred'], $overview['Hashing/Bcrypt rounds']);
        self::assertSame(['*', 'inferred'], $overview['CORS/Allowed origins']);
        self::assertSame([], $result->findings, 'A local environment produces no production findings.');
    }

    public function testProductionMisconfiguration(): void
    {
        $findings = self::findings($this->analyze(Fixtures::project('insecure-app')));

        self::assertSame(Severity::Medium, $findings['config.production-array-session']->severity);
        self::assertSame(Confidence::Confirmed, $findings['config.production-array-session']->confidence);
        self::assertSame('.env', $findings['config.production-array-session']->component);
        self::assertArrayHasKey('config.production-sync-queue', $findings);
        self::assertArrayHasKey('config.production-debug-logging', $findings);
        self::assertStringContainsString('SESSION_DRIVER', $findings['config.env-keys-not-in-example']->evidence);
        self::assertStringNotContainsString('Insecure-Prod-Pass', $findings['config.env-keys-not-in-example']->evidence, 'Only names are compared.');
    }

    public function testProductionChecksNeedAnEnvFile(): void
    {
        $root = $this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^10.0"}}',
            'config/app.php' => "<?php return ['env' => env('APP_ENV', 'production'), 'debug' => true];",
            'config/queue.php' => "<?php return ['default' => 'sync'];",
        ]);
        $result = $this->analyze($root);

        self::assertArrayNotHasKey('config.production-sync-queue', self::findings($result));
        self::assertArrayHasKey('config.no-env-files', self::findings($result));
        self::assertStringContainsString('production-only checks were skipped', implode(' ', $result->notes));
    }
}
