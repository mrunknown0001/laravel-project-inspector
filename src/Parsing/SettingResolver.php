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
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: string|bool}>
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
        if ($config->exists($file)) {
            $value = $config->get($file, $key);
            if ($value->isKnown()) {
                return $value;
            }
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

        $major = $this->context->project->laravelMajor;
        if (!$config->exists($file) && $major !== null && $major >= self::SLIM_SKELETON_MAJOR) {
            return new ConfigValue($default, Source::Inferred, sprintf('Laravel %d framework default (no config/%s.php)', $major, $file));
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
