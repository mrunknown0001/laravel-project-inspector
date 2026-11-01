<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Security;

use LaraInspect\Security\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    /**
     * Secrets are assembled at runtime so this file never contains a string
     * that looks like a real credential to secret scanners.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        $alnum = 'Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4z';

        yield 'env password' => ['DB_PASSWORD=hunter2-but-longer', 'hunter2-but-longer'];
        yield 'env quoted' => ['MAIL_PASSWORD="quoted value here"', 'quoted value here'];
        yield 'env export' => ['export STRIPE_SECRET=abc123def456', 'abc123def456'];
        yield 'env app key' => ['APP_KEY=base64:' . base64_encode(str_repeat('k', 32)), base64_encode(str_repeat('k', 32))];
        yield 'php array' => ["'password' => 'p@ssw0rd-value',", 'p@ssw0rd-value'];
        yield 'php array client secret' => ["'client_secret' => \"cl1ent-s3cret\"", 'cl1ent-s3cret'];
        yield 'json token' => ['{"api_token": "tok-123-456"}', 'tok-123-456'];
        yield 'url credentials' => ['redis://default:r3dis-pass@cache.internal:6379', 'r3dis-pass'];
        yield 'openai key' => ['key is sk-proj-' . $alnum, 'sk-proj-' . $alnum];
        yield 'anthropic key' => ['sk-ant-api03-' . $alnum, 'sk-ant-api03-' . $alnum];
        yield 'openrouter key' => ['sk-or-v1-' . $alnum, 'sk-or-v1-' . $alnum];
        yield 'notokenlimit key' => ['ntl_live_' . $alnum, 'ntl_live_' . $alnum];
        yield 'aws access key id' => ['AKIA' . 'ABCDEFGHIJKLMNOP', 'AKIA' . 'ABCDEFGHIJKLMNOP'];
        yield 'github token' => ['ghp_' . $alnum . 'abcd', 'ghp_' . $alnum . 'abcd'];
        yield 'slack token' => ['xoxb-' . '123456789012-abcdefghij', 'xoxb-' . '123456789012-abcdefghij'];
        yield 'stripe key' => ['sk_' . 'live_' . $alnum, 'sk_' . 'live_' . $alnum];
        yield 'jwt' => ['Bearer eyJ' . $alnum . '.eyJ' . $alnum . '.' . $alnum, 'eyJ' . $alnum . '.eyJ' . $alnum];
        yield 'hash inside an unquoted value' => ['API_TOKEN=xyz#1abc', 'xyz#1abc'];
        yield 'escaped quote in a quoted value' => ['KEY="ab\\"cd-rest-of-secret"', 'rest-of-secret'];
        yield 'lower-case key' => ['db_password=hunter2-lower', 'hunter2-lower'];
        yield 'env default' => ["env('DB_PASSWORD', 'fallback-secret-1')", 'fallback-secret-1'];
        yield 'at sign in url password' => ['mysql://user:p@ss-word@db.internal/app', 'ss-word'];
        yield 'private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY-----", 'MIIEpAIBAAKCAQEA'];
    }

    #[DataProvider('secrets')]
    public function testRedactsKnownSecretFormats(string $text, string $secret): void
    {
        $redacted = (new SecretRedactor())->redact($text);

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString('[REDACTED', $redacted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmless(): iterable
    {
        yield 'driver choice' => ['DB_CONNECTION=mysql'];
        yield 'secure cookie flag' => ['SESSION_SECURE_COOKIE=true'];
        yield 'php config' => ["'driver' => 'mysql',"];
        yield 'env call' => ["'password' => env('DB_PASSWORD', ''),"];
        yield 'prose' => ['Hardcoded secrets should be moved to the .env file.'];
        yield 'url without password' => ['https://example.test/path?x=1'];
        yield 'empty value' => ["'password' => '',"];
    }

    #[DataProvider('harmless')]
    public function testLeavesNonSecretsUntouched(string $text): void
    {
        self::assertSame($text, (new SecretRedactor())->redact($text));
    }

    public function testKnownSecretsAreRemovedWhereverTheyAppear(): void
    {
        $redactor = new SecretRedactor(['Xk9-unique-value-77']);

        self::assertSame('found [REDACTED] in a log', $redactor->redact('found Xk9-unique-value-77 in a log'));
    }

    public function testWordLikeKnownSecretsDoNotMangleProse(): void
    {
        // A weak DB_PASSWORD=secret must not blank the word "secret" across the report.
        $redactor = new SecretRedactor(['secret', 'password', 'abc']);

        self::assertSame('Rotate the secret and password.', $redactor->redact('Rotate the secret and password.'));
    }

    public function testLongerKnownSecretsWinOverTheirSubstrings(): void
    {
        $redactor = new SecretRedactor(['abcd-1234', 'abcd-1234-efgh-5678']);

        self::assertSame('[REDACTED]', $redactor->redact('abcd-1234-efgh-5678'));
    }

    public function testRedactArrayWalksNestedValuesButNotKeys(): void
    {
        $redactor = new SecretRedactor();
        $data = ['DB_PASSWORD' => 'safe', 'nested' => ['line' => 'DB_PASSWORD=topsecret1', 'n' => 3]];

        $result = $redactor->redactArray($data);

        self::assertSame('safe', $result['DB_PASSWORD']);
        self::assertSame('DB_PASSWORD=[REDACTED]', $result['nested']['line']);
        self::assertSame(3, $result['nested']['n']);
    }
}
