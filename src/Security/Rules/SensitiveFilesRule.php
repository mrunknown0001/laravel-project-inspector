<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Support\Evidence;

/**
 * Files that should never be web-accessible or committed: env files and their
 * backups, database dumps, private keys, logs, and debug scripts in public/.
 */
final class SensitiveFilesRule implements ProjectRuleInterface
{
    /** pattern => [label, severity] for files under public/ */
    private const PUBLIC_PATTERNS = [
        '#(^|/)\.env([.\-_].*)?$#i' => ['environment file', Severity::Critical],
        '#\.(sql|sql\.gz|dump|sqlite|sqlite3|db)$#i' => ['database file or dump', Severity::High],
        '#\.(bak|backup|old|orig|save|swp|tmp)$|~$#i' => ['backup or editor file', Severity::High],
        '#\.(zip|tar|tgz|tar\.gz|gz|7z|rar)$#i' => ['archive', Severity::High],
        '#(^|/)(phpinfo|info|test|debug|adminer[^/]*|phpmyadmin)\.php$#i' => ['debug or admin script', Severity::High],
        '#\.(pem|key|p12|pfx|crt)$|(^|/)id_(rsa|ecdsa|ed25519)#i' => ['key or certificate', Severity::High],
        '#\.log$#i' => ['log file', Severity::Medium],
        '#(^|/)(composer\.(json|lock)|package(-lock)?\.json|\.git/.*|\.htpasswd)$#i' => ['project metadata', Severity::Low],
    ];

    /** pattern => [label, severity] for files that should not be committed */
    private const COMMITTED_PATTERNS = [
        '#(^|/)storage/oauth-(private|public)\.key$#' => ['Passport OAuth key', Severity::High],
        '#\.(pem|key|p12|pfx)$|(^|/)id_(rsa|ecdsa|ed25519)(\.pub)?$#i' => ['private key or certificate bundle', Severity::High],
        '#\.(sql|sql\.gz|dump)$#i' => ['database dump', Severity::High],
        '#(^|/)database/[^/]+\.(sqlite|sqlite3|db)$#i' => ['SQLite database', Severity::Medium],
        '#(^|/)storage/logs/.+\.log$#' => ['application log', Severity::Medium],
    ];

    /** Env variants that are normally committed. */
    private const SAFE_ENV_VARIANTS = ['.env.example', '.env.testing', '.env.ci', '.env.dusk.example'];

    public function check(InspectionContext $context): array
    {
        return [
            ...$this->publicFiles($context),
            ...$this->envFiles($context),
            ...$this->committedFiles($context),
            ...$this->rootIndex($context),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function publicFiles(InspectionContext $context): array
    {
        $grouped = [];
        foreach ($context->files->findFiles('public') as $file) {
            $relative = substr($file, strlen('public/'));
            foreach (self::PUBLIC_PATTERNS as $pattern => [$label, $severity]) {
                if (preg_match($pattern, $relative) === 1) {
                    $grouped[$label]['severity'] = $severity;
                    $grouped[$label]['files'][] = $file;
                    break;
                }
            }
        }

        $findings = [];
        foreach ($grouped as $label => $group) {
            $findings[] = new Finding(
                'sec.public-sensitive-file',
                sprintf('Public directory contains %s', Evidence::plural(count($group['files']), $label, $label === 'archive' ? 'archives' : null)),
                $group['severity'],
                Confidence::Potential,
                'public/',
                sprintf('Everything under public/ can be downloaded by visitors unless the web server blocks it. A %s there may expose source code, data or credentials.', $label),
                Evidence::list($group['files']),
                'Remove these files from public/ (and from the server), and keep backups and dumps outside the web root.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function envFiles(InspectionContext $context): array
    {
        $findings = [];
        $tracked = $context->trackedFiles();

        if ($context->files->isFile('.env') && $tracked !== null && in_array('.env', $tracked, true)) {
            $hasSecrets = EnvFile::fromContents((string) $context->files->readIfExists('.env'), '.env')->secretValuesForRedaction() !== [];
            $findings[] = new Finding(
                'sec.env-committed',
                '.env is committed to version control',
                $hasSecrets ? Severity::Critical : Severity::High,
                Confidence::Confirmed,
                '.env',
                'The .env file holds environment-specific credentials. If it is committed, everyone with access to the repository, its forks and its history can read them.',
                sprintf('git ls-files lists .env%s.', $hasSecrets ? '; it contains non-empty values for sensitive keys' : ''),
                'Remove it from the index (git rm --cached .env), add it to .gitignore, and rotate every credential it contained, since it remains in git history.',
            );
        }

        $variants = [];
        foreach ($context->files->findFiles('', [], false) as $file) {
            if (preg_match('#^\.env[.\-_~].+|^\.env~$#', $file) === 1 && !in_array($file, self::SAFE_ENV_VARIANTS, true)) {
                $variants[] = $file;
            }
        }
        $committed = $tracked === null ? [] : array_values(array_intersect($variants, $tracked));
        if ($committed !== []) {
            $findings[] = new Finding(
                'sec.env-variant-committed',
                sprintf('%s committed to version control', Evidence::plural(count($committed), 'environment file variant is', 'environment file variants are')),
                Severity::High,
                Confidence::Confirmed,
                'version control',
                'Environment variants and backups (.env.production, .env.backup, ...) usually contain real credentials.',
                Evidence::list($committed),
                'Remove them from git (git rm --cached), add them to .gitignore, and rotate the credentials they contained.',
            );
        } elseif ($tracked === null && $variants !== []) {
            $findings[] = new Finding(
                'sec.env-variant-present',
                sprintf('%s present in the project root', Evidence::plural(count($variants), 'environment file variant'), ),
                Severity::Low,
                Confidence::Potential,
                'project root',
                'Copies of .env (production settings, backups) often contain real credentials and are easy to deploy or commit by accident. Whether they are tracked by git could not be determined.',
                Evidence::list($variants),
                'Make sure these files are git-ignored and excluded from deployments, or delete them.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function committedFiles(InspectionContext $context): array
    {
        $tracked = $context->trackedFiles();
        if ($tracked === null) {
            return [];
        }

        $grouped = [];
        foreach ($tracked as $file) {
            if (str_starts_with($file, 'vendor/') || str_starts_with($file, 'node_modules/') || str_starts_with($file, 'tests/')) {
                continue;
            }
            foreach (self::COMMITTED_PATTERNS as $pattern => [$label, $severity]) {
                if (preg_match($pattern, $file) === 1) {
                    $grouped[$label]['severity'] = $severity;
                    $grouped[$label]['files'][] = $file;
                    break;
                }
            }
        }

        $findings = [];
        foreach ($grouped as $label => $group) {
            $findings[] = new Finding(
                'sec.sensitive-file-committed',
                sprintf('%s committed to version control', ucfirst(Evidence::plural(count($group['files']), $label, $label . 's'))),
                $group['severity'],
                Confidence::Confirmed,
                'version control',
                sprintf('A %s in the repository is available to everyone with access to it, including its full history.', $label),
                Evidence::list($group['files']),
                'Remove the files from git (git rm --cached), add them to .gitignore, and rotate any keys or credentials they contain.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function rootIndex(InspectionContext $context): array
    {
        if (!$context->files->isFile('index.php') || !$context->files->isDirectory('public')) {
            return [];
        }

        return [new Finding(
            'sec.project-root-web-served',
            'The project root has an index.php, so it may be served as the web root',
            Severity::Medium,
            Confidence::Potential,
            'index.php',
            'Laravel expects the web server to serve only public/. An index.php in the project root is a common shared-hosting workaround that can make .env, storage/ and source files downloadable.',
            'index.php exists next to public/.',
            'Point the web server document root at public/ and remove the root index.php.',
        )];
    }
}
