<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Security;

use LaraInspect\Exception\FileAccessException;
use LaraInspect\Security\PathGuard;
use LaraInspect\Security\SafeFileReader;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class SafeFileReaderTest extends TestCase
{
    use UsesTemporaryDirectory;

    private function reader(string $root, int $maxBytes = 1024): SafeFileReader
    {
        return new SafeFileReader(PathGuard::forDirectory($root), $maxBytes, ['vendor', 'node_modules', 'storage', 'bootstrap/cache']);
    }

    public function testReadsFilesAndReportsExistence(): void
    {
        $reader = $this->reader($this->makeProject(['composer.json' => '{"name":"x/y"}', 'app/Models/User.php' => '<?php']));

        self::assertSame('{"name":"x/y"}', $reader->read('composer.json'));
        self::assertTrue($reader->isFile('app/Models/User.php'));
        self::assertTrue($reader->isDirectory('app'));
        self::assertFalse($reader->exists('missing.txt'));
        self::assertFalse($reader->exists('../anything'));
        self::assertNull($reader->readIfExists('missing.txt'));
        self::assertSame(['name' => 'x/y'], $reader->readJson('composer.json'));
        self::assertNull($reader->readJson('missing.json'));
    }

    public function testRefusesFilesOverTheSizeLimit(): void
    {
        $reader = $this->reader($this->makeProject(['big.txt' => str_repeat('x', 2048)]));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage('larger than');
        $reader->read('big.txt');
    }

    public function testPerCallLimitCannotExceedTheGlobalLimit(): void
    {
        $reader = $this->reader($this->makeProject(['mid.txt' => str_repeat('x', 600)]));

        self::assertSame(600, strlen($reader->read('mid.txt', 10_000)));
        $this->expectException(FileAccessException::class);
        $reader->read('mid.txt', 100);
    }

    public function testEscapingPathsSurfaceAsFileAccessErrors(): void
    {
        $reader = $this->reader($this->makeProject(['a.txt' => 'a']));

        $this->expectException(FileAccessException::class);
        $reader->read('../../etc/passwd');
    }

    public function testInvalidJsonIsReported(): void
    {
        $reader = $this->reader($this->makeProject(['composer.json' => '{"name": ']));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage('not valid JSON');
        $reader->readJson('composer.json');
    }

    public function testFindFilesSkipsExcludedDirectoriesAndFiltersExtensions(): void
    {
        $reader = $this->reader($this->makeProject([
            'app/Http/Controllers/HomeController.php' => '<?php',
            'app/Models/User.php' => '<?php',
            'app/readme.md' => '#',
            'vendor/acme/pkg/src/Thing.php' => '<?php',
            'app/node_modules/x/index.php' => '<?php',
            'storage/framework/views/compiled.php' => '<?php',
            'bootstrap/cache/services.php' => '<?php',
            'bootstrap/app.php' => '<?php',
        ]));

        self::assertSame(
            ['app/Http/Controllers/HomeController.php', 'app/Models/User.php'],
            $reader->findFiles('app', ['php']),
        );
        self::assertSame(['bootstrap/app.php'], $reader->findFiles('bootstrap', ['php']));
        self::assertSame([], $reader->findFiles('vendor', ['php']));
        self::assertTrue($reader->isExcluded('bootstrap/cache/packages.php'));
        self::assertFalse($reader->isExcluded('bootstrap/app.php'));
        self::assertTrue($reader->isExcluded('modules/blog/vendor/x.php'));
    }

    public function testFindFilesDoesNotFollowSymlinks(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows requires elevated privileges.');
        }
        $outside = $this->makeProject(['leak.php' => '<?php']);
        $root = $this->makeProject(['app/Real.php' => '<?php']);
        symlink($outside, $root . '/app/linked');

        self::assertSame(['app/Real.php'], $this->reader($root)->findFiles('app', ['php']));
    }

    public function testReaderHasNoWriteMethods(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => strtolower($m->getName()), (new \ReflectionClass(SafeFileReader::class))->getMethods());

        foreach (['write', 'put', 'delete', 'unlink', 'mkdir', 'rename', 'copy', 'touch', 'chmod'] as $verb) {
            foreach ($methods as $method) {
                self::assertStringNotContainsString($verb, $method, 'SafeFileReader must stay read-only.');
            }
        }
    }
}
