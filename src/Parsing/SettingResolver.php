<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Source;

/**
 * Resolves well-known Laravel settings (drivers, environment, debug).
 *
 * Order: the project's config file, then the .env keys Laravel reads for it,
 * then the framework default. Laravel 11+ applications often omit config files,
 * in which case the framework's own config applies.
 */
final class SettingResolver
{
    /**
     * setting => [config file, key path, env keys (newest first), framework default for Laravel 11+]
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: string|int|bool|null}>
     */
    private const SETTINGS = [
        'environment' => ['app', 'env', ['APP_ENV'], 'production'],
        'debug' => ['app', 'debug', ['APP_DEBUG'], false],
        'database' => ['database', 'default', ['DB_CONNECTION'], 'sqlite'],
        'cache' => ['cache', 'default', ['CACHE_STORE', 'CACHE_DRIVER'], 'database'],
        'queue' => ['queue', 'default', ['QUEUE_CONNECTION', 'QUEUE_DRIVER'], 'database'],
        'session' => ['session', 'driver', ['SESSION_DRIVER'], 'database'],
        'log' => ['logging', 'default', ['LOG_CHANNEL'], 'stack'],
        'filesystem' => ['filesystems', 'default', ['FILESYSTEM_DISK', 'FILESYSTEM_DRIVER'], 'local'],
        'mail' => ['mail', 'default', ['MAIL_MAILER', 'MAIL_DRIVER'], 'log'],
        'broadcast' => ['broadcasting', 'default', ['BROADCAST_CONNECTION', 'BROADCAST_DRIVER'], 'log'],
        'app.url' => ['app', 'url', ['APP_URL'], 'http://localhost'],
        'app.timezone' => ['app', 'timezone', ['APP_TIMEZONE'], 'UTC'],
        'app.locale' => ['app', 'locale', ['APP_LOCALE'], 'en'],
        'app.cipher' => ['app', 'cipher', [], 'AES-256-CBC'],
        'app.maintenance' => ['app', 'maintenance.driver', ['APP_MAINTENANCE_DRIVER'], 'file'],
        'session.lifetime' => ['session', 'lifetime', ['SESSION_LIFETIME'], 120],
        'session.encrypt' => ['session', 'encrypt', ['SESSION_ENCRYPT'], false],
        'session.secure' => ['session', 'secure', ['SESSION_SECURE_COOKIE'], null],
        'session.http_only' => ['session', 'http_only', ['SESSION_HTTP_ONLY'], true],
        'session.same_site' => ['session', 'same_site', ['SESSION_SAME_SITE'], 'lax'],
        'session.expire_on_close' => ['session', 'expire_on_close', ['SESSION_EXPIRE_ON_CLOSE'], false],
        'queue.failed' => ['queue', 'failed.driver', ['QUEUE_FAILED_DRIVER'], 'database-uuids'],
        'auth.guard' => ['auth', 'defaults.guard', ['AUTH_GUARD'], 'web'],
        'auth.password_timeout' => ['auth', 'password_timeout', ['AUTH_PASSWORD_TIMEOUT'], 10800],
        'hashing.driver' => ['hashing', 'driver', ['HASH_DRIVER'], 'bcrypt'],
        'hashing.bcrypt_rounds' => ['hashing', 'bcrypt.rounds', ['BCRYPT_ROUNDS'], 12],
    ];

    public const DATABASE_DRIVERS = [
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlite' => 'SQLite',
        'sqlsrv' => 'SQL Server',
    ];

    /** Laravel 11 moved framework defaults into the framework and stopped requiring config files. */
    private const SLIM_SKELETON_MAJOR = 11;

    public function __construct(
        private readonly InspectionContext $context,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function settings(): array
    {
        return array_keys(self::SETTINGS);
    }

    public function resolve(string $setting): ConfigValue
    {
        if (!isset(self::SETTINGS[$setting])) {
            throw new \InvalidArgumentException(sprintf('Unknown setting "%s".', $setting));
        }
        [$file, $key, $envKeys, $default] = self::SETTINGS[$setting];

        $config = $this->context->config();
        $definedInFile = false;
        if ($config->exists($file)) {
            $value = $config->get($file, $key);
            if ($value->isKnown()) {
                return $value;
            }
            $definedInFile = $value->found;
        }

        $env = $this->context->env();
        foreach ($envKeys as $envKey) {
            if ($env->isReadable($envKey)) {
                return new ConfigValue(
                    $env->typed($envKey),
                    $env->isExample() ? Source::Inferred : Source::Detected,
                    $env->origin() . ': ' . $envKey,
                );
            }
        }

        // Laravel 11+ merges published config over the framework's own, so a missing
        // file or key falls back to the framework default.
        $major = $this->context->project->laravelMajor;
        if (!$definedInFile && $major !== null && $major >= self::SLIM_SKELETON_MAJOR) {
            return new ConfigValue($default, Source::Inferred, $config->exists($file)
                ? sprintf('Laravel %d framework default (%s not set in config/%s.php)', $major, $key, $file)
                : sprintf('Laravel %d framework default (no config/%s.php)', $major, $file));
        }

        return ConfigValue::unknown(sprintf('config/%s.php:%s', $file, $key));
    }

    /**
     * Driver for a database connection name, e.g. "mysql" => "mysql", "tenant" => driver from config.
     */
    public function databaseDriver(string $connection): ?string
    {
        $driver = $this->context->config()->get('database', 'connections.' . $connection . '.driver');
        if ($driver->isKnown() && is_string($driver->value)) {
            return $driver->value;
        }

        return isset(self::DATABASE_DRIVERS[$connection]) ? $connection : null;
    }
}
