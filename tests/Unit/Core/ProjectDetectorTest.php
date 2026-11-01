<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Core;

use LaraInspect\Core\ProjectDetector;
use LaraInspect\Core\ProjectInfo;
use LaraInspect\Model\Source;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectDetectorTest extends TestCase
{
    use UsesTemporaryDirectory;

    private function detect(string $root): ProjectInfo
    {
        return (new ProjectDetector())->detect(Fixtures::reader($root));
    }

    /**
     * @return iterable<string, array{string, string, int, string, string}>
     */
    public static function laravelFixtures(): iterable
    {
        yield 'Laravel 8 from composer.lock' => ['laravel-8', '8.83.27', 8, 'composer.lock', ProjectInfo::LAYOUT_LEGACY];
        yield 'Laravel 10 from composer.lock' => ['laravel-10', '10.48.4', 10, 'composer.lock', ProjectInfo::LAYOUT_LEGACY];
        yield 'Laravel 11 modern skeleton' => ['laravel-11', '11.34.2', 11, 'composer.lock', ProjectInfo::LAYOUT_MODERN];
        yield 'Laravel 12 without lock, from vendor' => ['laravel-12', '12.8.1', 12, 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php', ProjectInfo::LAYOUT_MODERN];
    }

    #[DataProvider('laravelFixtures')]
    public function testDetectsLaravelProjectsAndVersions(string $fixture, string $version, int $major, string $origin, string $layout): void
    {
        $info = $this->detect(Fixtures::project($fixture));

        self::assertTrue($info->isLaravel);
        self::assertSame('laravel', $info->framework);
        self::assertSame($version, $info->laravelVersion);
        self::assertSame($major, $info->laravelMajor);
        self::assertSame(Source::Detected, $info->versionSource);
        self::assertSame($origin, $info->versionOrigin);
        self::assertSame($layout, $info->layout);
        self::assertTrue($info->isSupportedVersion());
        self::assertSame([], $info->warnings);
    }

    public function testRecordsWhichMarkersArePresent(): void
    {
        $markers = $this->detect(Fixtures::project('laravel-12'))->markers;

        self::assertTrue($markers['artisan']);
        self::assertTrue($markers['bootstrap/app.php']);
        self::assertFalse($markers['composer.lock']);
        self::assertSame(ProjectDetector::MARKERS, array_keys($markers));
    }

    public function testInfersTheMajorVersionFromTheConstraintAsLastResort(): void
    {
        $info = $this->detect($this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^10.10"}}',
            'bootstrap/app.php' => '<?php',
        ]));

        self::assertTrue($info->isLaravel);
        self::assertSame('10.x', $info->laravelVersion);
        self::assertSame(10, $info->laravelMajor);
        self::assertSame(Source::Inferred, $info->versionSource);
        self::assertStringContainsString('^10.10', (string) $info->versionOrigin);
    }

    public function testPrefersInstalledJsonOverConstraint(): void
    {
        $info = $this->detect($this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^9.0"}}',
            'vendor/composer/installed.json' => '{"packages": [{"name": "laravel/framework", "version": "v9.52.16"}]}',
        ]));

        self::assertSame('9.52.16', $info->laravelVersion);
        self::assertSame('vendor/composer/installed.json', $info->versionOrigin);
    }

    public function testOlderVersionsAreDetectedWithABestEffortWarning(): void
    {
        $info = $this->detect($this->makeProject([
            'artisan' => '<?php',
            'composer.json' => '{"require": {"laravel/framework": "^7.29"}}',
            'composer.lock' => '{"packages": [{"name": "laravel/framework", "version": "v7.30.6"}], "packages-dev": []}',
        ]));

        self::assertTrue($info->isLaravel);
        self::assertSame(7, $info->laravelMajor);
        self::assertFalse($info->isSupportedVersion());
        self::assertStringContainsString('best-effort', implode(' ', $info->warnings));
    }

    public function testLumenIsRecognisedButNotTreatedAsLaravel(): void
    {
        $info = $this->detect(Fixtures::project('lumen'));

        self::assertFalse($info->isLaravel);
        self::assertSame('lumen', $info->framework);
        self::assertStringContainsString('Lumen', implode(' ', $info->warnings));
    }

    public function testNonLaravelProjectIsRejected(): void
    {
        $info = $this->detect(Fixtures::project('not-laravel'));

        self::assertFalse($info->isLaravel);
        self::assertNull($info->framework);
        self::assertNull($info->laravelVersion);
        self::assertSame(ProjectInfo::LAYOUT_UNKNOWN, $info->layout);
    }

    public function testLaravelPackageWithoutApplicationFilesIsNotAnApplication(): void
    {
        $info = $this->detect($this->makeProject(['composer.json' => '{"require": {"laravel/framework": "^11.0"}}']));

        self::assertFalse($info->isLaravel);
        self::assertStringContainsString('package rather than an application', implode(' ', $info->warnings));
    }

    public function testInvalidComposerJsonIsReportedAsWarning(): void
    {
        $info = $this->detect($this->makeProject(['artisan' => '<?php', 'composer.json' => '{broken']));

        self::assertFalse($info->isLaravel);
        self::assertStringContainsString('not valid JSON', implode(' ', $info->warnings));
    }

    public function testRoundTripsThroughArrays(): void
    {
        $info = $this->detect(Fixtures::project('laravel-11'));

        self::assertEquals($info, ProjectInfo::fromArray($info->toArray()));
    }
}
