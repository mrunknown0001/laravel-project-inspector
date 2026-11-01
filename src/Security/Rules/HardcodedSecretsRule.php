<?php

declare(strict_types=1);

namespace LaraInspect\Security\Rules;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Finding;
use LaraInspect\Model\Severity;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Security\SecretPatterns;
use LaraInspect\Support\Evidence;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;

/**
 * Credentials written into source and config files.
 *
 * Values are never reported: evidence shows the location, the kind of
 * secret and a masked prefix only.
 */
final class HardcodedSecretsRule implements SourceRuleInterface
{
    /** @var array<string, list<string>> category => evidence lines */
    private array $hits = [];

    public function reset(): void
    {
        $this->hits = [];
    }

    public function visit(string $file, string $contents, ?array $stmts): void
    {
        $path = SourceFile::path($file);
        $this->scanTokens($file, $contents);
        $this->scanUrlCredentials($file, $contents);

        if ($stmts !== null) {
            $this->scanPhp($file, $stmts);
        } elseif (preg_match('#(^|/)\.env[.\-_]#', $path) === 1) {
            $this->scanEnvFile($file, $contents);
        } elseif (preg_match('/\.(json|ya?ml|js|jsx|ts|tsx|vue|xml|neon|ini)$/i', $path) === 1) {
            $this->scanAssignments($file, $contents);
        }
    }

    public function findings(InspectionContext $context): array
    {
        $definitions = [
            'token' => ['sec.secret-token', ['value matches a known credential format', 'values match known credential formats'], Severity::High, Confidence::Confirmed,
                'These values match the format of real API keys, tokens or private keys. Anyone with access to the code can use them, and once committed they stay in the git history.',
                'Revoke and rotate each credential, move it to the environment (.env / secret manager), and read it through config().'],
            'literal' => ['sec.secret-literal', ['secret is hardcoded', 'secrets are hardcoded'], Severity::Medium, Confidence::Confirmed,
                'Passwords, secrets or API keys are written as string literals. Even when the value is only used locally, committed credentials tend to be reused in other environments.',
                "Replace the literal with env('NAME') in a config file, set the value in each environment, and rotate it if it was ever real."],
            'env-default' => ['sec.secret-env-default', ['env() default contains a secret', 'env() defaults contain secrets'], Severity::Medium, Confidence::Potential,
                "env('X', 'value') falls back to the literal when the variable is missing, so the 'default' quietly becomes the production credential if an environment is misconfigured.",
                "Remove the default (env('DB_PASSWORD')) so a missing variable fails loudly, and rotate the value if it is real."],
            'url' => ['sec.secret-url-credentials', ['URL embeds a password', 'URLs embed passwords'], Severity::Medium, Confidence::Confirmed,
                'Connection strings such as redis://user:password@host include the password in source code and in logs that print the URL.',
                'Build the URL from environment variables or pass the password separately.'],
            'env-file' => ['sec.secret-in-env-variant', ['secret-looking value in an env file other than .env', 'secret-looking values in env files other than .env'], Severity::Medium, Confidence::Potential,
                'Env files other than .env (.env.example, .env.testing, backups) are often committed, copied or deployed. Real-looking values for sensitive keys there are likely live credentials.',
                'Replace the values with placeholders (or delete backup copies) and rotate any real credentials.'],
        ];

        $findings = [];
        foreach ($definitions as $category => [$id, [$singular, $plural], $severity, $confidence, $explanation, $remediation]) {
            $hits = $this->hits[$category] ?? [];
            if ($hits === []) {
                continue;
            }
            $findings[] = new Finding(
                $id,
                Evidence::plural(count($hits), $singular, $plural),
                $severity,
                $confidence,
                'source code',
                $explanation,
                Evidence::list($hits),
                $remediation,
            );
        }

        return $findings;
    }

    private function scanTokens(string $file, string $contents): void
    {
        foreach (SecretPatterns::TOKENS as $label => $pattern) {
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] as [$value, $offset]) {
                // Application keys in .env.example / config/app.php have their own, more specific findings.
                if (stripos($value, 'EXAMPLE') !== false
                    || ($label === 'Laravel application key' && in_array(SourceFile::path($file), ['.env.example', '.env.testing', 'config/app.php'], true))) {
                    continue;
                }
                $this->hits['token'][] = sprintf('%s: %s %s', Evidence::location($file, self::line($contents, $offset)), $label, SecretPatterns::mask($value));
            }
        }
    }

    private function scanUrlCredentials(string $file, string $contents): void
    {
        if (preg_match_all(SecretPatterns::URL_CREDENTIALS, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($matches as $match) {
            $password = $match[3][0];
            if (SecretPatterns::isPlaceholder($password) || preg_match('/[{}$%]|env\(/', $password) === 1) {
                continue;
            }
            $this->hits['url'][] = sprintf('%s: %s%s:%s@...', Evidence::location($file, self::line($contents, $match[0][1])), $match[1][0], $match[2][0], SecretPatterns::mask($password));
        }
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function scanPhp(string $file, array $stmts): void
    {
        $finder = new NodeFinder();

        foreach ($finder->find($stmts, static fn (Node $n): bool => $n instanceof Node\ArrayItem || $n instanceof Node\PropertyItem || $n instanceof Expr\Assign || $n instanceof Expr\FuncCall) as $node) {
            [$key, $value] = match (true) {
                $node instanceof Node\ArrayItem => [PhpSource::string($node->key), $node->value],
                $node instanceof Node\PropertyItem => [$node->name->toString(), $node->default],
                $node instanceof Expr\Assign => [$node->var instanceof Expr\Variable && is_string($node->var->name) ? $node->var->name : null, $node->expr],
                default => [null, null],
            };

            if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'env') {
                $envKey = PhpSource::string(PhpSource::arg($node, 0));
                $default = PhpSource::arg($node, 1);
                if ($envKey !== null && self::isSensitiveKey($envKey) && $default instanceof Scalar\String_ && self::looksLikeSecret($default->value)) {
                    $this->hits['env-default'][] = sprintf("%s: env('%s', %s)", Evidence::location($file, $node->getStartLine()), $envKey, SecretPatterns::mask($default->value));
                }
                continue;
            }

            if ($key === null || !self::isSensitiveKey($key) || !$value instanceof Scalar\String_ || !self::looksLikeSecret($value->value)) {
                continue;
            }
            if (self::matchesTokenFormat($value->value)) {
                continue; // already reported with its format
            }
            $this->hits['literal'][] = sprintf("%s: '%s' => %s", Evidence::location($file, $node->getStartLine()), $key, SecretPatterns::mask($value->value));
        }
    }

    private function scanEnvFile(string $file, string $contents): void
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*["\']?([^"\'#\s]*)/', $line, $m) !== 1) {
                continue;
            }
            if (self::isSensitiveKey($m[1]) && self::looksLikeSecret($m[2]) && !self::matchesTokenFormat($m[2])) {
                $this->hits['env-file'][] = sprintf('%s: %s (%s)', Evidence::location($file, $index + 1), SecretPatterns::mask($m[2]), $m[1]);
            }
        }
    }

    private function scanAssignments(string $file, string $contents): void
    {
        $pattern = '/["\']?([A-Za-z_][A-Za-z0-9_.\-]*)["\']?\s*[:=]\s*["\']([^"\'\r\n]{8,})["\']/';
        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($matches as $match) {
            $key = $match[1][0];
            $value = $match[2][0];
            if (self::isSensitiveKey($key) && self::looksLikeSecret($value) && !self::matchesTokenFormat($value)) {
                $this->hits['literal'][] = sprintf('%s: %s = %s', Evidence::location($file, self::line($contents, $match[0][1])), $key, SecretPatterns::mask($value));
            }
        }
    }

    private static function isSensitiveKey(string $key): bool
    {
        return preg_match(SecretPatterns::SENSITIVE_KEY_NAME, $key) === 1
            || preg_match('/(^|_)(KEY|SECRET|TOKEN|PASSWORD|PASS|PWD)$/', $key) === 1;
    }

    /**
     * Distinguishes credential-like strings from names such as 'remember_token' or 'password_reset_tokens'.
     */
    private static function looksLikeSecret(string $value): bool
    {
        if (SecretPatterns::isPlaceholder($value) || strlen($value) < 8 || preg_match('/\s/', $value) === 1) {
            return false;
        }
        if (preg_match('#^(https?://|/|\./|[a-z]+:)#i', $value) === 1 && !str_contains($value, '@')) {
            return false; // URLs, paths, and driver strings like "redis:" are not secrets themselves
        }
        if (preg_match('/^[a-z][a-z0-9]*([._\-][a-z0-9]+)*$/', $value) === 1 && preg_match('/\d/', $value) !== 1) {
            return strlen($value) >= 24; // lower-case identifiers (table names, config keys)
        }

        return preg_match('/[A-Z]/', $value) === 1 || preg_match('/\d/', $value) === 1 || preg_match('/[^A-Za-z0-9]/', $value) === 1;
    }

    private static function matchesTokenFormat(string $value): bool
    {
        foreach (SecretPatterns::TOKENS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function line(string $contents, int $offset): int
    {
        return substr_count($contents, "\n", 0, min($offset, strlen($contents))) + 1;
    }
}
