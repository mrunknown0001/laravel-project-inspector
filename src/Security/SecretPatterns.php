<?php

declare(strict_types=1);

namespace LaraInspect\Security;

/**
 * Credential formats shared by the redactor (to remove them) and the
 * secret scanner (to report them). One list, so the two cannot drift apart.
 */
final class SecretPatterns
{
    /** @var array<string, string> label => pattern */
    public const TOKENS = [
        'Private key' => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s',
        'Anthropic API key' => '/\bsk-ant-[A-Za-z0-9_\-]{16,}/',
        'OpenAI-style API key' => '/\bsk-(?:proj-|or-v1-)?[A-Za-z0-9_\-]{20,}/',
        'NoTokenLimit API key' => '/\bntl_live_[A-Za-z0-9_\-]{8,}/',
        'AWS access key ID' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'GitHub token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})/',
        'Slack token' => '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/',
        'Google API key' => '/\bAIza[0-9A-Za-z_\-]{30,}/',
        'Stripe secret key' => '/\b[sr]k_(?:live|test)_[0-9A-Za-z]{10,}/',
        'SendGrid API key' => '/\bSG\.[\w\-]{16,}\.[\w\-]{16,}/',
        'JSON Web Token' => '/\beyJ[A-Za-z0-9_\-]{8,}\.eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
        'Laravel application key' => '#\bbase64:[A-Za-z0-9+/]{20,}={0,2}#',
    ];

    /** scheme://user:password@host */
    public const URL_CREDENTIALS = '#\b([a-z][a-z0-9+.\-]*://)([^:/\s@\'"]*):([^/\s\'"]+)@#i';

    /** Key names whose values are credentials, for PHP/JSON/YAML style assignments. */
    public const SENSITIVE_KEY_NAME = '/^[A-Za-z0-9_.\-]*(?:password|passwd|secret|token|api_?key|private_?key|access_?key|auth_?key|credentials?|dsn|salt)[A-Za-z0-9_.\-]*$/i';

    /** Values that are obviously placeholders, not real secrets. */
    private const PLACEHOLDERS = [
        'null', 'none', 'secret', 'password', 'changeme', 'change-me', 'change_me', 'example', 'your-key', 'your-secret',
        'your-token', 'xxx', 'xxxx', 'todo', 'test', 'testing', 'root', 'admin', 'laravel', 'homestead', 'sail', 'forge',
    ];

    public static function isPlaceholder(string $value): bool
    {
        $normalised = strtolower(trim($value));

        return $normalised === ''
            || strlen($normalised) < 4
            || in_array($normalised, self::PLACEHOLDERS, true)
            || preg_match('/^(?:x+|\*+|\.+|-+|<[^>]*>|\{\{.*\}\}|\$\{.*\}|%[^%]+%|your[-_ ].*|.*[-_]here|example.*|dummy.*|fake.*|placeholder.*)$/i', $normalised) === 1;
    }

    /**
     * Shows enough of a secret to locate it, never enough to use it.
     */
    public static function mask(string $value): string
    {
        $length = strlen($value);

        return $length <= 8
            ? sprintf('**** (%d characters)', $length)
            : sprintf('%s**** (%d characters)', substr($value, 0, 4), $length);
    }
}
