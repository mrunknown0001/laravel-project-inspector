<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Process;

use LaraInspect\Process\ExecutableLocator;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ExecutableLocatorTest extends TestCase
{
    use UsesTemporaryDirectory;

    public function testIgnoresEmptyAndRelativePathEntries(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX executable bits are not available on Windows.');
        }
        $project = $this->tempDir() . '/project';
        $bin = $this->tempDir() . '/bin';
        mkdir($project);
        mkdir($bin);
        foreach ([$project . '/git', $bin . '/git'] as $file) {
            file_put_contents($file, "#!/bin/sh\n");
            chmod($file, 0o755);
        }
        $cwd = (string) getcwd();
        chdir($project);
        try {
            // "" and "." both mean the current directory, i.e. a project the user is standing in.
            self::assertNull((new ExecutableLocator(':.:bin:./vendor/bin', false))->find('git'));
            self::assertSame($bin . '/git', (new ExecutableLocator('::' . $bin, false))->find('git'));
        } finally {
            chdir($cwd);
        }
        self::assertNull((new ExecutableLocator($bin, false))->find('../bin/git'), 'Names with separators are rejected.');
        self::assertNull((new ExecutableLocator($bin, false))->find('missing-tool'));
    }

    public function testWindowsRulesForAbsoluteDirectories(): void
    {
        $locator = new ExecutableLocator('relative;.;;C:\\nope', true);

        self::assertNull($locator->find('composer'));
    }
}
