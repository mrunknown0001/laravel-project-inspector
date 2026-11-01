<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Security;

use LaraInspect\Analyzers\SecurityAnalyzer;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Process\ProcessResult;
use LaraInspect\Security\Rules\PermissionsRule;
use LaraInspect\Tests\Support\FakeProcessRunner;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class SecurityAnalyzerTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const COMPOSER = '{"require": {"php": "^8.2", "laravel/framework": "^11.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}';

    private static function analyze(string $root, ?FakeProcessRunner $runner = null, ?SecurityAnalyzer $analyzer = null): AnalyzerResult
    {
        return ($analyzer ?? new SecurityAnalyzer())->analyze(Fixtures::context($root, $runner, offline: true));
    }

    /**
     * @return array<string, Finding>
     */
    private static function byId(AnalyzerResult $result): array
    {
        $map = [];
        foreach ($result->findings as $finding) {
            $map[$finding->id] = $finding;
        }

        return $map;
    }

    /**
     * @param array<string, string> $files
     */
    private function project(array $files): string
    {
        return $this->makeProject($files + ['artisan' => '<?php', 'composer.json' => self::COMPOSER, 'bootstrap/app.php' => '<?php']);
    }

    public function testInsecureFixtureProducesTheExpectedFindings(): void
    {
        $findings = self::byId(self::analyze(Fixtures::project('insecure-app')));

        $expected = [
            'sec.debug-in-production' => [Severity::High, Confidence::Confirmed],
            'sec.app-key-missing' => [Severity::High, Confidence::Confirmed],
            'sec.app-key-hardcoded' => [Severity::High, Confidence::Confirmed],
            'sec.app-key-in-example' => [Severity::Medium, Confidence::Confirmed],
            'sec.cors-wildcard-credentials' => [Severity::High, Confidence::Confirmed],
            'sec.session-not-http-only' => [Severity::Medium, Confidence::Confirmed],
            'sec.session-same-site-none' => [Severity::Medium, Confidence::Potential],
            'sec.weak-bcrypt-rounds' => [Severity::Medium, Confidence::Confirmed],
            'sec.debug-package-in-production' => [Severity::Medium, Confidence::Confirmed],
            'sec.debug-tool-enabled-in-production' => [Severity::High, Confidence::Confirmed],
            'sec.public-sensitive-file' => [Severity::High, Confidence::Potential],
            'sec.env-variant-present' => [Severity::Low, Confidence::Potential],
            'sec.route-phpinfo' => [Severity::High, Confidence::Confirmed],
            'sec.route-dangerous-closure' => [Severity::High, Confidence::Potential],
            'sec.route-sensitive-public' => [Severity::Medium, Confidence::Potential],
            'sec.route-api-write-public' => [Severity::Low, Confidence::Potential],
            'sec.csrf-disabled' => [Severity::High, Confidence::Confirmed],
            'sec.trust-all-proxies' => [Severity::Low, Confidence::Potential],
            'sec.secret-token' => [Severity::High, Confidence::Confirmed],
            'sec.secret-literal' => [Severity::Medium, Confidence::Confirmed],
            'sec.secret-env-default' => [Severity::Medium, Confidence::Potential],
            'sec.secret-url-credentials' => [Severity::Medium, Confidence::Confirmed],
            'sec.secret-in-env-variant' => [Severity::Medium, Confidence::Potential],
            'sec.command-injection' => [Severity::High, Confidence::Potential],
            'sec.sql-injection' => [Severity::High, Confidence::Potential],
            'sec.raw-sql-concatenation' => [Severity::Medium, Confidence::Potential],
            'sec.unsafe-unserialize' => [Severity::Medium, Confidence::Potential],
            'sec.path-or-url-from-input' => [Severity::Medium, Confidence::Potential],
            'sec.open-redirect' => [Severity::Low, Confidence::Potential],
            'sec.mass-assignment-request-all' => [Severity::Low, Confidence::Potential],
            'sec.weak-password-hash' => [Severity::Medium, Confidence::Potential],
            'sec.debug-statement' => [Severity::Info, Confidence::Potential],
            'sec.model-unguard' => [Severity::Medium, Confidence::Potential],
            'sec.model-guarded-empty' => [Severity::Low, Confidence::Potential],
            'sec.blade-unescaped-input' => [Severity::High, Confidence::Potential],
            'sec.blade-unescaped-output' => [Severity::Low, Confidence::Potential],
        ];
        foreach ($expected as $id => [$severity, $confidence]) {
            self::assertArrayHasKey($id, $findings, "Missing {$id}");
            self::assertSame($severity, $findings[$id]->severity, $id);
            self::assertSame($confidence, $findings[$id]->confidence, $id);
        }

        // The parameterised query on the same controller is not flagged.
        self::assertSame(1, substr_count($findings['sec.sql-injection']->evidence, "\n- ") + 1);
        self::assertStringNotContainsString('id = ?', $findings['sec.sql-injection']->evidence . $findings['sec.raw-sql-concatenation']->evidence);
        // The safe Blade helper is not flagged.
        self::assertStringNotContainsString('csrf_field', $findings['sec.blade-unescaped-output']->evidence);
    }

    public function testEvidenceNeverContainsSecretValues(): void
    {
        $json = json_encode(array_map(static fn (Finding $f): array => $f->toArray(), self::analyze(Fixtures::project('insecure-app'))->findings), JSON_THROW_ON_ERROR);

        foreach (['Live-Literal-Secret-77aa', 'mg-Default-Secret-123', 'ntl_live_FixtureFakeKey123456', 'Redis-Pass-55', 'Old-Backup-Pass-123', 'Frontend-Secret-123abc', 'tok_Live_A1b2C3d4E5f6', 'Insecure-Prod-Pass-991'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertStringContainsString('ntl_****', $json, 'Masked prefixes help locate the value.');
    }

    public function testNameLikeValuesAreNotTreatedAsSecrets(): void
    {
        $root = $this->project([
            'config/auth.php' => "<?php return ['passwords' => ['users' => ['table' => 'password_reset_tokens']], 'token_name' => 'remember_token', 'password' => env('DB_PASSWORD', ''), 'secret' => env('SECRET'), 'api_key' => 'your-api-key-here'];",
            'app/Services/Thing.php' => "<?php\nnamespace App\\Services;\nclass Thing { private \$tokenName = 'payment_api_token'; private \$passwordField = 'password'; }\n",
            'resources/js/app.js' => "const password = this.form.password;\nconst apiKey = import.meta.env.VITE_KEY;\n",
        ]);

        self::assertSame([], array_filter(array_keys(self::byId(self::analyze($root))), static fn (string $id): bool => str_starts_with($id, 'sec.secret')));
    }

    public function testDangerousCodeDistinguishesInputFromStaticUse(): void
    {
        $root = $this->project([
            'app/Console/Backup.php' => <<<'PHP'
                <?php
                namespace App\Console;
                class Backup
                {
                    public function run(string $disk)
                    {
                        exec('mysqldump app > /tmp/app.sql');
                        $rows = DB::select('select * from users where id = ?', [$disk]);
                        $q = DB::table('users')->whereRaw('lower(email) = ?', [strtolower($disk)]);
                        $cfg = unserialize(file_get_contents('/tmp/cache'), ['allowed_classes' => false]);
                        $literal = unserialize('a:0:{}');
                        return Hash::make($disk);
                    }
                }
                PHP,
        ]);
        $findings = self::byId(self::analyze($root));

        self::assertArrayHasKey('sec.command-execution', $findings);
        self::assertArrayNotHasKey('sec.command-injection', $findings);
        self::assertArrayNotHasKey('sec.sql-injection', $findings);
        self::assertArrayNotHasKey('sec.raw-sql-concatenation', $findings);
        self::assertArrayNotHasKey('sec.unsafe-unserialize', $findings);
    }

    public function testCommittedFilesAreDetectedThroughGit(): void
    {
        $root = $this->project([
            '.env' => "APP_KEY=base64:" . base64_encode(str_repeat('x', 32)) . "\nDB_PASSWORD=real-pass-123\n",
            '.env.production' => "DB_PASSWORD=prod-pass-123\n",
            'storage/oauth-private.key' => 'key',
            'database/dump.sql' => '--',
            'storage/logs/laravel.log' => 'log',
            'app/Models/User.php' => '<?php',
        ]);
        $git = (new FakeProcessRunner())->respond('git -c', ProcessResult::completed(0, implode("\0", ['.env', '.env.production', 'storage/oauth-private.key', 'database/dump.sql', 'storage/logs/laravel.log', 'composer.json']) . "\0"));

        $findings = self::analyze($root, $git)->findings;
        $byTitle = array_map(static fn (Finding $f): string => $f->id . ' ' . $f->severity->value . ' ' . $f->title, $findings);

        self::assertContains('sec.env-committed CRITICAL .env is committed to version control', $byTitle);
        self::assertContains('sec.env-variant-committed HIGH 1 environment file variant is committed to version control', $byTitle);
        self::assertContains('sec.sensitive-file-committed HIGH 1 Passport OAuth key committed to version control', $byTitle);
        self::assertContains('sec.sensitive-file-committed HIGH 1 database dump committed to version control', $byTitle);
        self::assertContains('sec.sensitive-file-committed MEDIUM 1 application log committed to version control', $byTitle);

        $command = $git->commands[0];
        self::assertContains('core.fsmonitor=false', $command->arguments, 'Git must not run repository-configured fsmonitor hooks.');
        self::assertFalse($command->sideEffects);
    }

    public function testModernMiddlewareConfigurationIsRead(): void
    {
        $root = $this->project([
            'bootstrap/app.php' => <<<'PHP'
                <?php
                return Application::configure(basePath: dirname(__DIR__))
                    ->withMiddleware(function (Middleware $middleware) {
                        $middleware->validateCsrfTokens(except: ['stripe/*', 'webhooks/*']);
                        $middleware->trustProxies(at: '*');
                    })->create();
                PHP,
        ]);
        $findings = self::byId(self::analyze($root));

        self::assertSame(Severity::Info, $findings['sec.csrf-exclusions']->severity);
        self::assertStringContainsString('stripe/*', $findings['sec.csrf-exclusions']->evidence);
        self::assertArrayHasKey('sec.trust-all-proxies', $findings);
        self::assertArrayNotHasKey('sec.csrf-disabled', $findings);
    }

    public function testApplicationKeyReusedFromExampleIsDetectedByHash(): void
    {
        $key = 'base64:' . base64_encode(str_repeat('k', 32));
        $root = $this->project(['.env' => "APP_KEY={$key}\n", '.env.example' => "APP_KEY={$key}\n"]);
        $finding = self::byId(self::analyze($root))['sec.app-key-reused-from-example'];

        self::assertSame(Severity::High, $finding->severity);
        self::assertStringNotContainsString(base64_encode(str_repeat('k', 32)), $finding->evidence);
    }

    public function testCleanProjectHasNoHighSeverityFindings(): void
    {
        $findings = self::analyze(Fixtures::project('laravel-11'), analyzer: new SecurityAnalyzer(null, null))->findings;
        $serious = array_filter($findings, static fn (Finding $f): bool => $f->severity->isAtLeast(Severity::High));

        self::assertSame([], array_map(static fn (Finding $f): string => $f->title, array_values($serious)));
    }

    public function testPermissionsOnPosixFilesystems(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permissions are not available on Windows.');
        }
        $root = $this->project(['.env' => 'APP_ENV=production', 'app/Models/User.php' => '<?php', 'storage/logs/.gitkeep' => '']);
        chmod($root . '/.env', 0o666);
        chmod($root . '/app/Models/User.php', 0o666);
        chmod($root . '/storage', 0o777);

        $rule = new PermissionsRule('', false);
        $ids = array_map(static fn (Finding $f): string => $f->id . ':' . $f->severity->value, $rule->check(Fixtures::context($root)));

        self::assertContains('sec.env-permissions:HIGH', $ids);
        self::assertContains('sec.world-writable-code:MEDIUM', $ids);
        self::assertContains('sec.world-writable-storage:LOW', $ids);
    }

    public function testPermissionsAreSkippedOnWindowsBackedMounts(): void
    {
        $root = $this->project(['.env' => 'APP_ENV=production']);
        $mounts = "/dev/sdc / ext4 rw 0 0\nC:\\134 " . dirname($root) . " 9p rw,dirsync,aname=drvfs 0 0\n";

        $rule = new PermissionsRule($mounts, false);
        self::assertSame([], $rule->check(Fixtures::context($root)));
        self::assertStringContainsString('9p mount', (string) $rule->skipReason());

        $windows = new PermissionsRule('', true);
        self::assertSame([], $windows->check(Fixtures::context($root)));
        self::assertStringContainsString('Windows', (string) $windows->skipReason());
    }

    public function testAFailingRuleIsReportedAndDoesNotStopTheOthers(): void
    {
        $broken = new class implements \LaraInspect\Security\Rules\ProjectRuleInterface {
            public function check(\LaraInspect\Core\InspectionContext $context): array
            {
                throw new \RuntimeException('rule exploded');
            }
        };
        $result = self::analyze(Fixtures::project('insecure-app'), analyzer: new SecurityAnalyzer([$broken, new \LaraInspect\Security\Rules\MiddlewareRule()], []));

        self::assertStringContainsString('rule exploded', implode(' ', $result->notes));
        self::assertArrayHasKey('sec.csrf-disabled', self::byId($result));
    }
}
