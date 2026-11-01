<?php

declare(strict_types=1);

namespace LaraInspect\Security;

/**
 * Removes credentials from text before it is shown, saved or sent anywhere.
 *
 * Three layers, applied in order:
 *  1. Exact values known to be secret (e.g. values of sensitive .env keys).
 *  2. Well-known credential formats (private keys, API tokens, JWTs, URL passwords).
 *  3. Assignments whose key name looks sensitive (DB_PASSWORD=..., 'secret' => '...').
 *
 * Redaction errs on the side of removing too much.
 */
final class SecretRedactor
{
    public const MASK = '[REDACTED]';

    /** Values shorter than this are too generic to replace by exact match. */
    private const MIN_KNOWN_SECRET_LENGTH = 8;

    /** Letter-only values shorter than this look like words ("password") and would mangle prose. */
    private const MIN_PLAIN_WORD_SECRET_LENGTH = 16;

    private const SENSITIVE_WORDS = 'KEY|SECRET|TOKEN|PASSWORD|PASSWD|PWD|PASS|CREDENTIALS?|PRIVATE|AUTH|DSN|SALT|SIGNATURE';

    /** @var list<string> */
    private array $knownSecrets = [];

    /**
     * @param list<string> $knownSecrets
     */
    public function __construct(array $knownSecrets = [])
    {
        $this->addKnownSecrets($knownSecrets);
    }

    /**
     * @param list<string> $secrets
     */
    public function addKnownSecrets(array $secrets): void
    {
        foreach ($secrets as $secret) {
            $secret = trim($secret);
            if (!self::isDistinctiveSecret($secret) || in_array($secret, $this->knownSecrets, true)) {
                continue;
            }
            $this->knownSecrets[] = $secret;
        }
        // Longest first so a secret containing another secret is replaced whole.
        usort($this->knownSecrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    private static function isDistinctiveSecret(string $secret): bool
    {
        if (strlen($secret) < self::MIN_KNOWN_SECRET_LENGTH) {
            return false;
        }

        return strlen($secret) >= self::MIN_PLAIN_WORD_SECRET_LENGTH || preg_match('/[^A-Za-z]/', $secret) === 1;
    }

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if ($this->knownSecrets !== []) {
            $text = str_replace($this->knownSecrets, self::MASK, $text);
        }

        foreach (SecretPatterns::TOKENS as $label => $pattern) {
            $text = preg_replace($pattern, $label === 'Private key' ? '[REDACTED PRIVATE KEY]' : self::MASK, $text) ?? $text;
        }
        // scheme://user:password@host keeps the user so the host is still identifiable.
        $text = preg_replace(SecretPatterns::URL_CREDENTIALS, '$1$2:' . self::MASK . '@', $text) ?? $text;

        // ENV style: DB_PASSWORD=value, export API_KEY="value", db_password=value.
        // An unquoted value runs to the next whitespace ("#" can be part of it).
        $text = preg_replace(
            '/\b([A-Z0-9_]*(?:' . self::SENSITIVE_WORDS . ')[A-Z0-9_]*)(\s*=\s*)("(?:[^"\\\\\n]|\\\\.)*"|\'[^\'\n]*\'|\S+)/i',
            '$1$2' . self::MASK,
            $text,
        ) ?? $text;

        // env('DB_PASSWORD', 'fallback-secret')
        $text = preg_replace(
            '/(env\(\s*([\'"])[A-Z0-9_]*(?:' . self::SENSITIVE_WORDS . ')[A-Z0-9_]*\2\s*,\s*)([\'"])(?:(?!\3)[^\\\\\n]|\\\\.)+\3/i',
            '$1$3' . self::MASK . '$3',
            $text,
        ) ?? $text;

        // PHP / JSON style: 'password' => 'value', "client_secret": "value"
        $text = preg_replace(
            '/([\'"])([A-Za-z0-9_.\-]*(?:password|passwd|secret|token|api_?key|private_?key|access_?key|auth_?key|credentials?|dsn|salt)[A-Za-z0-9_.\-]*)\1(\s*(?:=>|:)\s*)([\'"])(?:(?!\4)[^\\\\\n]|\\\\.)+\4/i',
            '$1$2$1$3$4' . self::MASK . '$4',
            $text,
        ) ?? $text;

        return $text;
    }

    /**
     * Recursively redacts every string value (not keys) in an array.
     *
     * @template T of array
     * @param T $data
     * @return T
     */
    public function redactArray(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->redact($value);
            }
        });

        return $data;
    }
}
