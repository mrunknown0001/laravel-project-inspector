<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\EnvironmentProfile;
use LaraInspect\Support\Evidence;

/**
 * World-readable/writable files where POSIX permissions are meaningful.
 *
 * Skipped on Windows and on Windows-backed mounts (WSL /mnt/c, 9p, vboxsf,
 * CIFS), where every file reports mode 777 regardless of real access rules.
 */
final class PermissionsRule implements ProjectRuleInterface
{
    private const NON_POSIX_FILESYSTEMS = ['drvfs', '9p', 'v9fs', 'vboxsf', 'cifs', 'smb3', 'smbfs', 'ntfs', 'ntfs3', 'fuseblk', 'vfat', 'exfat', 'msdos'];

    private const CODE_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes'];

    private const WRITABLE_DIRECTORIES = ['storage', 'bootstrap/cache'];

    private ?string $skipReason = null;

    /**
     * @param string|null $mounts Contents of /proc/mounts (injectable for tests).
     */
    public function __construct(
        private readonly ?string $mounts = null,
        private readonly ?bool $windows = null,
    ) {
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    public function check(InspectionContext $context): array
    {
        $this->skipReason = $this->unsupportedReason($context->root());
        if ($this->skipReason !== null) {
            return [];
        }

        $findings = [];
        $root = $context->root();
        $mode = static fn (string $relative): ?int => ($perms = @fileperms($root . DIRECTORY_SEPARATOR . $relative)) === false ? null : $perms & 0o777;

        $env = $context->files->isFile('.env') ? $mode('.env') : null;
        if ($env !== null && ($env & 0o006) !== 0) {
            $writable = ($env & 0o002) !== 0;
            // 0644 is normal on a developer machine; it matters on shared servers.
            $production = EnvironmentProfile::fromContext($context)->isProduction();
            $findings[] = new Finding(
                'sec.env-permissions',
                $writable ? '.env is world-writable' : '.env is world-readable',
                $writable ? Severity::High : ($production ? Severity::Medium : Severity::Low),
                Confidence::Confirmed,
                '.env',
                $writable
                    ? 'Any local user or compromised process can change the application\'s credentials and configuration.'
                    : 'Any local user or process on the server can read the application\'s credentials.',
                sprintf('Mode %04o', $env),
                'Restrict it to the owner and the web server group, e.g. chmod 640 .env.',
            );
        }

        $worldWritable = [];
        $candidates = ['artisan', 'composer.json', ...self::CODE_DIRECTORIES];
        foreach ($candidates as $path) {
            $m = $context->files->exists($path) ? $mode($path) : null;
            if ($m !== null && ($m & 0o002) !== 0) {
                $worldWritable[] = sprintf('%s (%04o)', $path, $m);
            }
        }
        foreach ($context->sourceFiles(['php'], array_values(array_diff(self::CODE_DIRECTORIES, ['public', 'resources']))) as $file) {
            $m = $mode($file);
            if ($m !== null && ($m & 0o002) !== 0) {
                $worldWritable[] = sprintf('%s (%04o)', $file, $m);
            }
        }
        if ($worldWritable !== []) {
            $findings[] = new Finding(
                'sec.world-writable-code',
                sprintf('%s world-writable', Evidence::plural(count($worldWritable), 'code path is', 'code paths are')),
                Severity::Medium,
                Confidence::Confirmed,
                'file permissions',
                'If application code can be modified by any user on the server, a compromise of any other service or account becomes a compromise of this application.',
                Evidence::list($worldWritable),
                'Remove world write permission (chmod o-w), and give the web server write access only to storage/ and bootstrap/cache/.',
            );
        }

        $writableData = [];
        foreach (self::WRITABLE_DIRECTORIES as $directory) {
            $m = $context->files->isDirectory($directory) ? $mode($directory) : null;
            if ($m !== null && ($m & 0o002) !== 0) {
                $writableData[] = sprintf('%s (%04o)', $directory, $m);
            }
        }
        if ($writableData !== []) {
            $findings[] = new Finding(
                'sec.world-writable-storage',
                'Writable application directories are world-writable',
                Severity::Low,
                Confidence::Confirmed,
                'file permissions',
                'storage/ and bootstrap/cache/ must be writable by the web server, but mode 777 lets every local user plant files such as compiled views or cached config, which Laravel then executes.',
                Evidence::list($writableData),
                'Use group permissions instead: chown -R deploy:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache.',
            );
        }

        return $findings;
    }

    private function unsupportedReason(string $root): ?string
    {
        if ($this->windows ?? DIRECTORY_SEPARATOR === '\\') {
            return 'File permission checks were skipped: POSIX permissions are not meaningful on Windows.';
        }

        $mounts = $this->mounts ?? (is_readable('/proc/mounts') ? (string) @file_get_contents('/proc/mounts') : '');
        $best = null;
        foreach (explode("\n", $mounts) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 3) {
                continue;
            }
            $mountPoint = str_replace('\\040', ' ', $parts[1]);
            if (($root === $mountPoint || str_starts_with($root, rtrim($mountPoint, '/') . '/')) && strlen($mountPoint) > strlen($best[0] ?? '')) {
                $best = [$mountPoint, strtolower($parts[2])];
            }
        }
        if ($best !== null && in_array($best[1], self::NON_POSIX_FILESYSTEMS, true)) {
            return sprintf('File permission checks were skipped: the project is on a %s mount (%s), where permissions do not reflect real access rules.', $best[1], $best[0]);
        }

        return null;
    }
}
