<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Security;

use LaraInspect\Exception\InvalidProjectException;
use LaraInspect\Exception\UnsafePathException;
use LaraInspect\Security\PathGuard;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testResolvesPathsInsideTheRoot(): void
    {
        $root = $this->makeProject(['config/app.php' => '<?php']);
        $guard = PathGuard::forDirectory($root);

        self::assertSame($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', $guard->resolve('config/app.php'));
        self::assertSame($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', $guard->resolve('config/../config/./app.php'));
        self::assertSame($root, $guard->resolve(''));
        self::assertSame('config/app.php', $guard->relative($guard->resolve('config\\app.php')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'parent directory' => ['../outside.txt'];
        yield 'nested escape' => ['config/../../outside.txt'];
        yield 'backslash escape' => ['config\\..\\..\\outside.txt'];
        yield 'absolute unix' => ['/etc/passwd'];
        yield 'absolute windows' => ['C:\\Windows\\win.ini'];
        yield 'stream wrapper' => ['php://filter/resource=index.php'];
        yield 'null byte' => ["config/app.php\0.txt"];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsPathsThatEscapeTheRoot(string $path): void
    {
        $guard = PathGuard::forDirectory($this->makeProject(['a.txt' => 'a']));

        $this->expectException(UnsafePathException::class);
        $guard->resolve($path);
    }

    public function testRejectsSymlinksPointingOutsideTheRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows requires elevated privileges.');
        }
        $outside = $this->makeProject(['secret.txt' => 'top secret']);
        $root = $this->makeProject(['inside.txt' => 'ok']);
        symlink($outside . '/secret.txt', $root . '/link.txt');

        $guard = PathGuard::forDirectory($root);

        $this->expectException(UnsafePathException::class);
        $guard->resolve('link.txt');
    }

    public function testAllowsSymlinksThatStayInsideTheRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Creating symlinks on Windows requires elevated privileges.');
        }
        $root = $this->makeProject(['real/file.txt' => 'ok']);
        symlink($root . '/real/file.txt', $root . '/alias.txt');

        self::assertSame($root . '/alias.txt', PathGuard::forDirectory($root)->resolve('alias.txt'));
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(InvalidProjectException::class);
        PathGuard::forDirectory($this->tempDir() . '/does-not-exist');
    }

    public function testFileIsNotAcceptedAsProjectRoot(): void
    {
        $root = $this->makeProject(['file.txt' => 'x']);

        $this->expectException(InvalidProjectException::class);
        PathGuard::forDirectory($root . '/file.txt');
    }

    public function testIsWithinDoesNotMatchSiblingPrefixes(): void
    {
        self::assertTrue(PathGuard::isWithin('/srv/app/config', '/srv/app'));
        self::assertTrue(PathGuard::isWithin('/srv/app', '/srv/app/'));
        self::assertFalse(PathGuard::isWithin('/srv/application', '/srv/app'));
        self::assertFalse(PathGuard::isWithin('/srv', '/srv/app'));
    }
}
