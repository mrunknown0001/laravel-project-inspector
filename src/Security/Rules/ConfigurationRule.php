<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\ConfigValue;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Parsing\EnvironmentProfile;
use LaraInspect\Parsing\SettingResolver;
use LaraInspect\Security\SecretPatterns;

/**
 * Debug mode, application key, session cookies, CORS, hashing and debug tooling.
 */
final class ConfigurationRule implements ProjectRuleInterface
{
    private const DEBUG_PACKAGES = [
        'barryvdh/laravel-debugbar' => 'Laravel Debugbar',
        'itsgoingd/clockwork' => 'Clockwork',
        'spatie/laravel-ray' => 'Ray',
        'beyondcode/laravel-dump-server' => 'Dump Server',
    ];

    public function check(InspectionContext $context): array
    {
        $settings = new SettingResolver($context);
        $profile = EnvironmentProfile::fromContext($context);

        return [
            ...$this->debugMode($profile, $settings->resolve('debug')),
            ...$this->applicationKey($context),
            ...$this->sessionCookies($profile, $settings),
            ...$this->cors($context),
            ...$this->hashing($profile, $settings->resolve('hashing.bcrypt_rounds')),
            ...$this->debugTooling($context, $profile),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function debugMode(EnvironmentProfile $profile, ConfigValue $debug): array
    {
        if (!$profile->isProduction() || $debug->value !== true) {
            return [];
        }

        return [new Finding(
            'sec.debug-in-production',
            'Debug mode is enabled in a production environment',
            Severity::High,
            $profile->confidence($debug),
            self::file($debug, '.env'),
            'With APP_DEBUG=true, error pages show stack traces, configuration values and environment variables, including credentials, to anyone who triggers an error. Older debug page versions have also had remote code execution vulnerabilities.',
            sprintf('%s; APP_DEBUG=true (%s).', $profile->describe(), $debug->origin),
            'Set APP_DEBUG=false in the production environment and run "php artisan config:cache" after deploying.',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function applicationKey(InspectionContext $context): array
    {
        $findings = [];
        $files = $context->files;
        $env = $files->isFile('.env') ? EnvFile::fromContents((string) $files->readIfExists('.env'), '.env') : null;
        $example = $files->isFile('.env.example') ? EnvFile::fromContents((string) $files->readIfExists('.env.example'), '.env.example') : null;

        if ($env !== null && !$env->isFilled('APP_KEY')) {
            $findings[] = new Finding(
                'sec.app-key-missing',
                'APP_KEY is not set',
                Severity::High,
                Confidence::Confirmed,
                '.env',
                'Laravel uses APP_KEY to encrypt cookies, sessions and encrypted model attributes. Without it, encryption fails. A key added later invalidates existing encrypted data.',
                $env->has('APP_KEY') ? 'APP_KEY is defined in .env but empty.' : 'APP_KEY is not defined in .env.',
                'Generate a key with "php artisan key:generate" in each environment, and store it in the environment rather than in code.',
            );
        }

        if ($example !== null && $example->isFilled('APP_KEY')) {
            $reused = $env !== null && $env->valueHash('APP_KEY') !== null && $env->valueHash('APP_KEY') === $example->valueHash('APP_KEY');
            $findings[] = new Finding(
                $reused ? 'sec.app-key-reused-from-example' : 'sec.app-key-in-example',
                $reused ? 'The application key is the one published in .env.example' : '.env.example contains an application key',
                $reused ? Severity::High : Severity::Medium,
                Confidence::Confirmed,
                '.env.example',
                $reused
                    ? 'The APP_KEY in .env is identical to the one in .env.example, which is usually committed. Anyone with the repository can decrypt cookies and forge sessions.'
                    : 'Example env files are normally committed. A real key there may be copied into environments, and anyone with the repository could decrypt their data.',
                $reused ? 'APP_KEY in .env and .env.example have the same value (compared by hash; values not shown).' : 'APP_KEY in .env.example is not empty.',
                'Leave APP_KEY empty in .env.example and generate a unique key per environment with "php artisan key:generate". Rotate any key that has been shared.',
            );
        }

        $configKey = $context->config()->get('app', 'key');
        if ($configKey->isKnown() && is_string($configKey->value) && str_starts_with($configKey->origin, 'config/') && !SecretPatterns::isPlaceholder($configKey->value)) {
            $findings[] = new Finding(
                'sec.app-key-hardcoded',
                'The application key is hardcoded in config/app.php',
                Severity::High,
                Confidence::Confirmed,
                'config/app.php',
                'A key written into a committed config file is shared by every environment and everyone with access to the repository.',
                sprintf("'key' => %s", SecretPatterns::mask($configKey->value)),
                "Use 'key' => env('APP_KEY'), rotate the exposed key, and set APP_KEY per environment.",
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function sessionCookies(EnvironmentProfile $profile, SettingResolver $settings): array
    {
        $findings = [];
        $httpOnly = $settings->resolve('session.http_only');
        if ($httpOnly->isKnown() && $httpOnly->value === false) {
            $findings[] = new Finding(
                'sec.session-not-http-only',
                'Session cookie is readable by JavaScript',
                Severity::Medium,
                $httpOnly->source === \LaraInspect\Model\Source::Detected ? Confidence::Confirmed : Confidence::Potential,
                self::file($httpOnly, 'config/session.php'),
                'With http_only disabled, any cross-site scripting flaw can steal session cookies and take over accounts.',
                sprintf('session.http_only = false (%s)', $httpOnly->origin),
                "Set 'http_only' => true in config/session.php (the Laravel default).",
            );
        }

        $sameSite = $settings->resolve('session.same_site');
        $secure = $settings->resolve('session.secure');
        if (strtolower((string) $sameSite->asString()) === 'none' && $secure->value !== true) {
            $findings[] = new Finding(
                'sec.session-same-site-none',
                'SameSite=None session cookie without the Secure flag',
                Severity::Medium,
                Confidence::Potential,
                self::file($sameSite, 'config/session.php'),
                'SameSite=None sends the session cookie on cross-site requests, which weakens CSRF protection. Browsers reject SameSite=None cookies that are not also Secure.',
                sprintf('same_site = none (%s); secure = %s (%s)', $sameSite->origin, $secure->display(), $secure->origin),
                'Use SameSite "lax" (the default) unless cross-site embedding is required; if it is, set SESSION_SECURE_COOKIE=true.',
            );
        }

        $url = (string) $settings->resolve('app.url')->asString();
        // An unknown value (e.g. computed at runtime) is not evidence of an insecure cookie.
        if ($profile->isProduction() && str_starts_with(strtolower($url), 'https://') && $secure->isKnown() && $secure->value !== true) {
            $findings[] = new Finding(
                'sec.session-cookie-not-secure',
                'Session cookie is not marked Secure in production',
                Severity::Low,
                Confidence::Potential,
                self::file($secure, 'config/session.php'),
                'The application is served over HTTPS but the session cookie may also be sent over plain HTTP, where it can be intercepted (e.g. on a downgraded or mixed-content request).',
                sprintf('%s; APP_URL=%s; session.secure = %s (%s)', $profile->describe(), $url, $secure->display(), $secure->origin),
                'Set SESSION_SECURE_COOKIE=true in the production environment.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function cors(InspectionContext $context): array
    {
        $config = $context->config();
        if (!$config->exists('cors')) {
            return [];
        }
        $origins = array_map(static fn (ConfigValue $v): ?string => $v->asString(), $config->list('cors', 'allowed_origins') ?? []);
        $credentials = $config->get('cors', 'supports_credentials');
        $wildcard = in_array('*', $origins, true);

        if ($wildcard && $credentials->value === true) {
            return [new Finding(
                'sec.cors-wildcard-credentials',
                'CORS allows any origin together with credentials',
                Severity::High,
                $credentials->source === \LaraInspect\Model\Source::Detected ? Confidence::Confirmed : Confidence::Potential,
                'config/cors.php',
                'With supports_credentials enabled, Laravel reflects the requesting origin when allowed_origins is "*". Any website could then make authenticated requests with the user\'s cookies and read the responses.',
                sprintf("'allowed_origins' => ['*'], 'supports_credentials' => true (%s)", $credentials->origin),
                'List the exact front-end origins in allowed_origins (or allowed_origins_patterns) when credentials are required.',
            )];
        }

        $patterns = array_map(static fn (ConfigValue $v): ?string => $v->asString(), $config->list('cors', 'allowed_origins_patterns') ?? []);
        foreach ($patterns as $pattern) {
            if ($pattern !== null && in_array($pattern, ['*', '.*', '/.*/', '#.*#'], true)) {
                return [new Finding(
                    'sec.cors-wildcard-pattern',
                    'CORS origin pattern matches every origin',
                    $credentials->value === true ? Severity::High : Severity::Low,
                    Confidence::Potential,
                    'config/cors.php',
                    'An origin pattern that matches everything has the same effect as allowing any origin.',
                    sprintf("'allowed_origins_patterns' contains '%s'", $pattern),
                    'Restrict the pattern to your own domains.',
                )];
            }
        }

        return [];
    }

    /**
     * @return list<Finding>
     */
    private function hashing(EnvironmentProfile $profile, ConfigValue $rounds): array
    {
        if (!$profile->isProduction() || !is_numeric($rounds->value) || (int) $rounds->value >= 10) {
            return [];
        }

        return [new Finding(
            'sec.weak-bcrypt-rounds',
            sprintf('Bcrypt work factor is %d in production', (int) $rounds->value),
            Severity::Medium,
            $profile->confidence($rounds),
            self::file($rounds, 'config/hashing.php'),
            'A low bcrypt cost makes stolen password hashes much faster to crack. Low values are normal in tests, not in production.',
            sprintf('%s; bcrypt rounds = %d (%s)', $profile->describe(), (int) $rounds->value, $rounds->origin),
            'Use at least 12 rounds (the Laravel default) in production; keep low values only in phpunit.xml / .env.testing.',
        )];
    }

    /**
     * @return list<Finding>
     */
    private function debugTooling(InspectionContext $context, EnvironmentProfile $profile): array
    {
        $findings = [];
        $require = $context->composer()->require();
        foreach (self::DEBUG_PACKAGES as $package => $label) {
            if (!isset($require[$package])) {
                continue;
            }
            $findings[] = new Finding(
                'sec.debug-package-in-production',
                sprintf('%s is a production dependency', $label),
                Severity::Medium,
                Confidence::Confirmed,
                'composer.json',
                sprintf('%s is listed under "require", so it is installed in production. Debugging tools expose queries, request data and configuration when enabled by mistake.', $label),
                sprintf('"require": {"%s": "%s"}', $package, $require[$package]),
                sprintf('Move %s to "require-dev" (composer require --dev %s) so production installs with --no-dev exclude it.', $package, $package),
            );
        }

        $env = $context->env();
        if ($profile->isProduction()) {
            foreach (['DEBUGBAR_ENABLED' => 'Laravel Debugbar', 'TELESCOPE_ENABLED' => 'Telescope'] as $key => $label) {
                if ($env->typed($key) === true) {
                    $findings[] = new Finding(
                        'sec.debug-tool-enabled-in-production',
                        sprintf('%s is enabled in production', $label),
                        $key === 'DEBUGBAR_ENABLED' ? Severity::High : Severity::Low,
                        $profile->confidence(),
                        $env->origin(),
                        $key === 'DEBUGBAR_ENABLED'
                            ? 'Debugbar renders queries, session data and request details into every page.'
                            : 'Telescope records requests, queries, jobs and exceptions. That is acceptable only if its dashboard gate is restricted.',
                        sprintf('%s; %s=true', $profile->describe(), $key),
                        $key === 'DEBUGBAR_ENABLED'
                            ? 'Set DEBUGBAR_ENABLED=false in production.'
                            : 'Make sure the Telescope gate in TelescopeServiceProvider allows only trusted users, or disable Telescope in production.',
                    );
                }
            }
        }

        return $findings;
    }

    private static function file(ConfigValue $value, string $fallback): string
    {
        $file = explode(':', $value->origin, 2)[0];

        return str_starts_with($file, '.env') || str_starts_with($file, 'config/') ? $file : $fallback;
    }
}
