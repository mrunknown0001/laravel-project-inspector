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

    /** @var array<string, string> pattern => replacement */
    private const TOKEN_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s' => '[REDACTED PRIVATE KEY]',
        // scheme://user:password@host keeps the user so the host is still identifiable.
        '#\b([a-z][a-z0-9+.\-]*://)([^:/\s@]*):([^@/\s]+)@#i' => '$1$2:' . self::MASK . '@',
        '/\bsk-ant-[A-Za-z0-9_\-]{16,}/' => self::MASK,
        '/\bsk-(?:proj-|or-v1-)?[A-Za-z0-9_\-]{20,}/' => self::MASK,
        '/\bntl_live_[A-Za-z0-9_\-]{8,}/' => self::MASK,
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => self::MASK,
        '/\bgh[pousr]_[A-Za-z0-9]{30,}/' => self::MASK,
        '/\bgithub_pat_[A-Za-z0-9_]{30,}/' => self::MASK,
        '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/' => self::MASK,
        '/\bAIza[0-9A-Za-z_\-]{30,}/' => self::MASK,
        '/\b[sr]k_(?:live|test)_[0-9A-Za-z]{10,}/' => self::MASK,
        '/\bSG\.[\w\-]{16,}\.[\w\-]{16,}/' => self::MASK,
        '/\beyJ[A-Za-z0-9_\-]{8,}\.eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/' => self::MASK,
        // Laravel APP_KEY format.
        '#\bbase64:[A-Za-z0-9+/]{20,}={0,2}#' => self::MASK,
    ];

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

        foreach (self::TOKEN_PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        // ENV style: DB_PASSWORD=value, export API_KEY="value"
        $text = preg_replace(
            '/\b([A-Z0-9_]*(?:' . self::SENSITIVE_WORDS . ')[A-Z0-9_]*)(\s*=\s*)("[^"\n]*"|\'[^\'\n]*\'|[^\s#]+)/',
            '$1$2' . self::MASK,
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
