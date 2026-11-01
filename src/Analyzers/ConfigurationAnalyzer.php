<?php

declare(strict_types=1);

namespace LaraInspect\Analyzers;

use LaraInspect\Contracts\AnalyzerInterface;
use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Model\Source;
use LaraInspect\Model\Table;
use LaraInspect\Parsing\ConfigValue;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Parsing\EnvironmentProfile;
use LaraInspect\Parsing\SettingResolver;
use LaraInspect\Support\Evidence;

/**
 * Application, session, cache, queue, logging, CORS, filesystem, hashing and
 * auth configuration, each value labelled detected / inferred / unknown.
 *
 * Security-relevant configuration findings (debug mode, APP_KEY, cookies,
 * CORS) are produced by the security analyzer; this one reports operational
 * misconfiguration.
 */
final class ConfigurationAnalyzer implements AnalyzerInterface
{
    /** area => [label => setting] */
    private const OVERVIEW = [
        'Application' => [
            'Environment' => 'environment', 'Debug mode' => 'debug', 'URL' => 'app.url', 'Timezone' => 'app.timezone',
            'Locale' => 'app.locale', 'Cipher' => 'app.cipher', 'Maintenance driver' => 'app.maintenance',
        ],
        'Session' => [
            'Driver' => 'session', 'Lifetime (minutes)' => 'session.lifetime', 'Encrypted' => 'session.encrypt',
            'Secure cookie' => 'session.secure', 'HTTP-only cookie' => 'session.http_only', 'SameSite' => 'session.same_site',
            'Expire on close' => 'session.expire_on_close',
        ],
        'Cache' => ['Default store' => 'cache'],
        'Queue' => ['Default connection' => 'queue', 'Failed job driver' => 'queue.failed'],
        'Logging' => ['Default channel' => 'log'],
        'Filesystem' => ['Default disk' => 'filesystem'],
        'Authentication' => ['Default guard' => 'auth.guard', 'Password confirmation timeout (s)' => 'auth.password_timeout'],
        'Hashing' => ['Driver' => 'hashing.driver', 'Bcrypt rounds' => 'hashing.bcrypt_rounds'],
    ];

    public function name(): string
    {
        return 'configuration';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function analyze(InspectionContext $context): AnalyzerResult
    {
        $settings = new SettingResolver($context);
        $profile = EnvironmentProfile::fromContext($context);
        $env = $context->env();
        $notes = [];
        $values = [];

        $rows = [];
        foreach (self::OVERVIEW as $area => $entries) {
            foreach ($entries as $label => $setting) {
                $value = $settings->resolve($setting);
                $values[$setting] = $value;
                $rows[] = [$area, $label, $value->display(), $value->source->value, $value->origin];
            }
        }

        $level = $this->logLevel($context, $values['log']);
        $values['log.level'] = $level;
        $rows[] = ['Logging', 'Level', $level->display(), $level->source->value, $level->origin];

        [$corsRows, $cors] = $this->cors($context);
        array_push($rows, ...$corsRows);

        $rows[] = ['Application', 'Application key', $this->appKeyStatus($env), $env->exists() ? Source::Detected->value : Source::Unknown->value, $env->exists() ? $env->origin() . ': APP_KEY (value never read)' : 'no .env file'];

        $disks = $context->config()->keys('filesystems', 'disks');
        $diskRows = [];
        foreach ($disks as $disk) {
            $driver = $context->config()->get('filesystems', 'disks.' . $disk . '.driver');
            $visibility = $context->config()->get('filesystems', 'disks.' . $disk . '.visibility');
            $diskRows[] = [$disk, $driver->display(), $visibility->found ? $visibility->display() : 'private (default)'];
        }

        $guards = [];
        foreach ($context->config()->keys('auth', 'guards') as $guard) {
            $guards[] = [$guard, $context->config()->get('auth', 'guards.' . $guard . '.driver')->display(), $context->config()->get('auth', 'guards.' . $guard . '.provider')->display()];
        }

        $facts = [
            new Fact('Environment file', $env->exists() ? $env->origin() : 'None found', Source::Detected, null),
            new Fact('Environment', $profile->name ?? 'unknown', $profile->source, $profile->origin),
            new Fact('Published config files', (string) count($context->files->findFiles('config', ['php'], false)), Source::Detected, 'config/'),
        ];
        if ($context->files->isFile('bootstrap/cache/config.php')) {
            $notes[] = 'bootstrap/cache/config.php exists: the application may be running from cached configuration that differs from the files analysed here.';
        }
        if (!$profile->fromEnvFile()) {
            $notes[] = 'APP_ENV is not set in any .env file, so production-only checks were skipped.';
        }

        $findings = [
            ...$this->productionFindings($profile, $values),
            ...$this->envFileFindings($context),
        ];

        $tables = [new Table('Configuration overview', ['Area', 'Setting', 'Value', 'Source', 'Origin'], $rows)];
        if ($diskRows !== []) {
            $tables[] = new Table('Filesystem disks', ['Disk', 'Driver', 'Visibility'], $diskRows);
        }
        if ($guards !== []) {
            $tables[] = new Table('Authentication guards', ['Guard', 'Driver', 'Provider'], $guards);
        }

        return new AnalyzerResult(
            $this->name(),
            $this->title(),
            facts: $facts,
            tables: $tables,
            findings: $findings,
            notes: $notes,
            data: [
                'environment' => ['name' => $profile->name, 'source' => $profile->source->value, 'from_env_file' => $profile->fromEnvFile()],
                'settings' => array_map(static fn (ConfigValue $v): array => ['value' => $v->asString(), 'source' => $v->source->value], $values),
                'cors' => $cors,
                'disks' => array_column($diskRows, 1, 0),
            ],
        );
    }

    private function appKeyStatus(EnvFile $env): string
    {
        return match (true) {
            !$env->exists() => 'Unknown (no .env file)',
            $env->isFilled('APP_KEY') => 'Set',
            $env->has('APP_KEY') => 'Empty',
            default => 'Not defined',
        };
    }

    private function logLevel(InspectionContext $context, ConfigValue $channel): ConfigValue
    {
        $name = $channel->asString();
        if ($name !== null && $context->config()->exists('logging')) {
            $level = $context->config()->get('logging', 'channels.' . $name . '.level');
            if ($level->isKnown()) {
                return $level;
            }
            // A stack channel delegates to its first child.
            $children = $context->config()->list('logging', 'channels.' . $name . '.channels');
            $first = $children[0] ?? null;
            if ($first !== null && $first->asString() !== null) {
                $level = $context->config()->get('logging', 'channels.' . $first->asString() . '.level');
                if ($level->isKnown()) {
                    return $level;
                }
            }
        }
        $env = $context->env();
        if ($env->isReadable('LOG_LEVEL')) {
            return new ConfigValue($env->get('LOG_LEVEL'), $env->isExample() ? Source::Inferred : Source::Detected, $env->origin() . ': LOG_LEVEL');
        }

        return new ConfigValue('debug', Source::Inferred, "Laravel default (env('LOG_LEVEL', 'debug'))");
    }

    /**
     * @return array{0: list<list<string>>, 1: array<string, mixed>}
     */
    private function cors(InspectionContext $context): array
    {
        $config = $context->config();
        $major = $context->project->laravelMajor ?? 0;
        if (!$config->exists('cors')) {
            if ($major >= 11) {
                $origin = 'Laravel framework default (no config/cors.php)';

                return [[
                    ['CORS', 'Paths', 'api/*, sanctum/csrf-cookie', Source::Inferred->value, $origin],
                    ['CORS', 'Allowed origins', '*', Source::Inferred->value, $origin],
                    ['CORS', 'Supports credentials', 'false', Source::Inferred->value, $origin],
                ], ['paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_origins' => ['*'], 'supports_credentials' => false, 'source' => 'inferred']];
            }

            return [[['CORS', 'Configuration', 'No config/cors.php', Source::Detected->value, 'config/']], ['source' => 'none']];
        }

        $rows = [];
        $data = ['source' => 'config/cors.php'];
        foreach (['paths' => 'Paths', 'allowed_origins' => 'Allowed origins', 'allowed_origins_patterns' => 'Allowed origin patterns', 'allowed_methods' => 'Allowed methods'] as $key => $label) {
            $items = $config->list('cors', $key);
            if ($items === null) {
                $value = $config->get('cors', $key);
                $rows[] = ['CORS', $label, $value->found ? $value->display('computed at runtime') : 'not set', $value->source->value, 'config/cors.php:' . $key];
                $data[$key] = null;
                continue;
            }
            $strings = array_map(static fn (ConfigValue $v): string => $v->display('?'), $items);
            $rows[] = ['CORS', $label, $strings === [] ? '(none)' : implode(', ', $strings), Source::Detected->value, 'config/cors.php:' . $key];
            $data[$key] = $strings;
        }
        $credentials = $config->get('cors', 'supports_credentials');
        $rows[] = ['CORS', 'Supports credentials', $credentials->display(), $credentials->source->value, $credentials->origin];
        $data['supports_credentials'] = $credentials->value;

        return [$rows, $data];
    }

    /**
     * @param array<string, ConfigValue> $values
     * @return list<Finding>
     */
    private function productionFindings(EnvironmentProfile $profile, array $values): array
    {
        if (!$profile->isProduction()) {
            return [];
        }
        $findings = [];

        $queue = $values['queue'];
        if ($queue->asString() === 'sync') {
            $findings[] = new Finding(
                'config.production-sync-queue',
                'Queued jobs run synchronously in production',
                Severity::Low,
                $profile->confidence($queue),
                self::component($queue, 'config/queue.php'),
                'With the "sync" queue connection, queued jobs, mail and notifications run inside the web request. This slows responses, and a failing job fails the request.',
                sprintf('%s; queue connection "sync" (%s).', $profile->describe(), $queue->origin),
                'Use a real queue backend (database, redis, sqs) in production and run a queue worker.',
            );
        }

        foreach (['cache' => 'Cache store', 'session' => 'Session driver'] as $setting => $label) {
            $value = $values[$setting];
            if ($value->asString() === 'array') {
                $findings[] = new Finding(
                    'config.production-array-' . $setting,
                    sprintf('%s is "array" in production', $label),
                    Severity::Medium,
                    $profile->confidence($value),
                    self::component($value, 'config/' . $setting . '.php'),
                    'The array driver keeps data only for the current request. Cached values are lost immediately, and with sessions users cannot stay logged in.',
                    sprintf('%s; %s "array" (%s).', $profile->describe(), strtolower($label), $value->origin),
                    'Use a persistent driver (redis, database, file, memcached) in production.',
                );
            }
        }

        $level = $values['log.level'];
        if (in_array(strtolower((string) $level->asString()), ['debug'], true)) {
            $findings[] = new Finding(
                'config.production-debug-logging',
                'Log level is "debug" in production',
                Severity::Low,
                $profile->confidence($level),
                self::component($level, 'config/logging.php'),
                'Debug-level logging in production produces large logs and is more likely to record request data, queries or tokens.',
                sprintf('%s; log level "debug" (%s).', $profile->describe(), $level->origin),
                'Set LOG_LEVEL to "info", "warning" or "error" in the production environment.',
            );
        }

        $url = $values['app.url'];
        $urlString = (string) $url->asString();
        if (str_starts_with(strtolower($urlString), 'http://') && preg_match('#^http://(localhost|127\.0\.0\.1)(:|/|$)#i', $urlString) !== 1) {
            $findings[] = new Finding(
                'config.production-http-url',
                'APP_URL uses plain HTTP in production',
                Severity::Low,
                Confidence::Potential,
                self::component($url, 'config/app.php'),
                'Generated links, redirects and signed URLs use APP_URL. Plain HTTP links expose cookies and tokens in transit unless a proxy rewrites them.',
                sprintf('%s; APP_URL=%s', $profile->describe(), $urlString),
                'Serve the application over HTTPS and set APP_URL to the https:// address.',
            );
        }

        return $findings;
    }

    /**
     * The file a value came from: ".env", "config/queue.php", or the given fallback.
     */
    private static function component(ConfigValue $value, string $fallback): string
    {
        $file = explode(':', $value->origin, 2)[0];

        return str_starts_with($file, '.env') || str_starts_with($file, 'config/') ? $file : $fallback;
    }

    /**
     * @return list<Finding>
     */
    private function envFileFindings(InspectionContext $context): array
    {
        $files = $context->files;
        $hasEnv = $files->isFile('.env');
        $hasExample = $files->isFile('.env.example');
        $findings = [];

        if (!$hasEnv && !$hasExample) {
            $findings[] = new Finding(
                'config.no-env-files',
                'No .env or .env.example file',
                Severity::Info,
                Confidence::Confirmed,
                '.env.example',
                'Without an example environment file, new developers and deployments have no list of the settings the application expects.',
                'Neither .env nor .env.example exists in the project root.',
                'Add a .env.example listing every required variable with placeholder values.',
            );
        }

        if ($hasEnv && $hasExample) {
            $env = EnvFile::fromContents((string) $files->readIfExists('.env'), '.env');
            $example = EnvFile::fromContents((string) $files->readIfExists('.env.example'), '.env.example');
            $missing = array_values(array_diff($env->keys(), $example->keys()));
            if ($missing !== []) {
                $findings[] = new Finding(
                    'config.env-keys-not-in-example',
                    sprintf('%s in .env %s missing from .env.example', Evidence::plural(count($missing), 'variable'), count($missing) === 1 ? 'is' : 'are'),
                    Severity::Info,
                    Confidence::Confirmed,
                    '.env.example',
                    'Variables used locally but absent from .env.example are easy to forget when deploying or onboarding. Only variable names are compared; values are never read.',
                    Evidence::list($missing),
                    'Add the missing variable names to .env.example with placeholder values.',
                );
            }
        }

        return $findings;
    }
}
