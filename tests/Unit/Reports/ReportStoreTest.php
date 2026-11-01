<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Reports;

use LaraInspect\Exception\InspectorException;
use LaraInspect\Reports\JsonReporter;
use LaraInspect\Reports\MarkdownReporter;
use LaraInspect\Reports\ReportStore;
use LaraInspect\Tests\Support\SampleReport;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportStoreTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testWritesReportsWithTimestampedNames(): void
    {
        $cwd = $this->tempDir();
        $store = ReportStore::at('reports', $cwd);

        $path = $store->write(SampleReport::create('/srv/My App'), new MarkdownReporter());

        self::assertSame($cwd . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'my-app-20261101-120000.md', $path);
        self::assertFileExists($path);
        self::assertSame($cwd . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'audit.log', $store->auditLogPath());
    }

    public function testWritesToAnExplicitTarget(): void
    {
        $cwd = $this->tempDir();
        $path = ReportStore::at('reports', $cwd)->write(SampleReport::create(), new JsonReporter(), 'out/nested/report.json', $cwd);

        self::assertSame($cwd . DIRECTORY_SEPARATOR . 'out/nested/report.json', $path);
        self::assertJson((string) file_get_contents($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targetsInsideProject(): iterable
    {
        yield 'directly inside' => ['project/report.md'];
        yield 'nested new directory' => ['project/docs/new/report.md'];
        yield 'via dot-dot' => ['elsewhere/../project/report.md'];
    }

    #[DataProvider('targetsInsideProject')]
    public function testRefusesToWriteInsideTheInspectedProject(string $target): void
    {
        $cwd = $this->tempDir();
        mkdir($cwd . '/project');
        mkdir($cwd . '/elsewhere');
        $store = ReportStore::at('reports', $cwd, $cwd . '/project');

        try {
            $store->write(SampleReport::create(), new MarkdownReporter(), $target, $cwd);
            self::fail('Expected the write to be refused.');
        } catch (InspectorException $e) {
            self::assertStringContainsString('inside the inspected project', $e->getMessage());
        }
        self::assertSame(['.', '..'], scandir($cwd . '/project'));
    }

    public function testRefusesAnOutputDirectoryInsideTheProject(): void
    {
        $project = $this->tempDir();

        $this->expectException(InspectorException::class);
        ReportStore::at('reports', $project, $project)->ensureDirectory();
    }

    public function testRefusesSymlinkedTargetsThatResolveIntoTheProject(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows requires elevated privileges.');
        }
        $cwd = $this->tempDir();
        mkdir($cwd . '/project');
        symlink($cwd . '/project', $cwd . '/innocent');

        $this->expectException(InspectorException::class);
        ReportStore::at('reports', $cwd, $cwd . '/project')->write(SampleReport::create(), new MarkdownReporter(), 'innocent/report.md', $cwd);
    }

    public function testFindsAndLoadsTheLatestJson(): void
    {
        $cwd = $this->tempDir();
        $store = ReportStore::at('reports', $cwd);
        self::assertNull($store->latestJson());

        $older = $store->write(SampleReport::create('/srv/older'), new JsonReporter());
        touch($older, time() - 60);
        $newer = $store->write(SampleReport::create('/srv/newer'), new JsonReporter());

        self::assertSame($newer, $store->latestJson());
        self::assertSame('/srv/newer', ReportStore::load($newer)->project->root);
    }

    public function testLoadRejectsBadFiles(): void
    {
        $file = $this->tempDir() . '/bad.json';
        file_put_contents($file, '{"schema_version": 1, "generated_at": "not a date"}');

        try {
            ReportStore::load($file);
            self::fail('Expected an exception.');
        } catch (InspectorException $e) {
            self::assertStringContainsString('cannot be loaded', $e->getMessage());
        }

        file_put_contents($file, 'not json');
        $this->expectException(InspectorException::class);
        $this->expectExceptionMessage('not valid JSON');
        ReportStore::load($file);
    }
}
