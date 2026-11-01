<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\Parsing;

use LaraInspect\Model\Source;
use LaraInspect\Parsing\ConfigReader;
use LaraInspect\Parsing\EnvFile;
use LaraInspect\Tests\Support\Fixtures;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConfigReaderTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const CONFIG = <<<'PHP'
        <?php

        // A side effect that must never run: config files are parsed, not included.
        file_put_contents(__DIR__ . '/../EXECUTED', 'yes');

        return [
            'literal' => 'redis',
            'number' => 120,
            'negative' => -1,
            'flag' => true,
            'nothing' => null,
            'from_env' => env('CACHE_STORE', 'file'),
            'default_only' => env('UNSET_KEY', 'fallback'),
            'no_default' => env('UNSET_KEY'),
            'nested' => env('CACHE_STORE_NEW', env('CACHE_DRIVER', 'array')),
            'cast' => (bool) env('APP_DEBUG', false),
            'sensitive' => env('DB_PASSWORD', 'default-pass'),
            'computed' => storage_path('framework/cache'),
            'concat' => 'prefix_' . env('APP_NAME'),
            'stores' => [
                'redis' => ['driver' => 'redis', 'connection' => 'cache'],
                'file' => ['driver' => 'file'],
            ],
        ];
        PHP;

    private function reader(string $env = "CACHE_STORE=memcached\nCACHE_DRIVER=redis\nAPP_DEBUG=true\nDB_PASSWORD=s3cr3t-value\n"): ConfigReader
    {
        $root = $this->makeProject(['config/cache.php' => self::CONFIG, 'config/broken.php' => '<?php return [']);

        return new ConfigReader(Fixtures::reader($root), EnvFile::fromContents($env, '.env'));
    }

    public function testResolvesLiteralsAsDetected(): void
    {
        $reader = $this->reader();

        self::assertSame('redis', $reader->get('cache', 'literal')->value);
        self::assertSame(120, $reader->get('cache', 'number')->value);
        self::assertSame(-1, $reader->get('cache', 'negative')->value);
        self::assertTrue($reader->get('cache', 'flag')->value);
        self::assertSame('null', $reader->get('cache', 'nothing')->display());
        self::assertSame(Source::Detected, $reader->get('cache', 'literal')->source);
        self::assertSame('file', $reader->get('cache', 'stores.file.driver')->value);
    }

    public function testResolvesEnvCallsAgainstTheEnvFile(): void
    {
        $reader = $this->reader();

        $fromEnv = $reader->get('cache', 'from_env');
        self::assertSame('memcached', $fromEnv->value);
        self::assertSame(Source::Detected, $fromEnv->source);
        self::assertSame('.env: CACHE_STORE', $fromEnv->origin);

        $default = $reader->get('cache', 'default_only');
        self::assertSame('fallback', $default->value);
        self::assertSame(Source::Inferred, $default->source);

        self::assertNull($reader->get('cache', 'no_default')->value);
        self::assertSame(Source::Inferred, $reader->get('cache', 'no_default')->source);

        $nested = $reader->get('cache', 'nested');
        self::assertSame('redis', $nested->value);
        self::assertSame(Source::Detected, $nested->source);

        self::assertTrue($reader->get('cache', 'cast')->value);
    }

    public function testNestedDefaultsAreInferredWhenNothingIsSet(): void
    {
        $nested = $this->reader('')->get('cache', 'nested');

        self::assertSame('array', $nested->value);
        self::assertSame(Source::Inferred, $nested->source);
    }

    public function testSensitiveEnvValuesAreNeverRead(): void
    {
        $value = $this->reader()->get('cache', 'sensitive');

        self::assertNull($value->value);
        self::assertSame(Source::Unknown, $value->source);
        self::assertStringContainsString('value not read', $value->origin);
        self::assertStringNotContainsString('s3cr3t', $value->origin);
    }

    public function testRuntimeExpressionsAreUnknown(): void
    {
        $reader = $this->reader();

        self::assertFalse($reader->get('cache', 'computed')->isKnown());
        self::assertFalse($reader->get('cache', 'concat')->isKnown());
    }

    public function testMissingKeysFilesAndParseErrors(): void
    {
        $reader = $this->reader();

        self::assertFalse($reader->get('cache', 'does.not.exist')->found);
        self::assertFalse($reader->get('missing', 'default')->found);
        self::assertFalse($reader->exists('missing'));
        self::assertStringContainsString('could not be parsed', $reader->get('broken', 'x')->origin);
        self::assertSame(['redis', 'file'], $reader->keys('cache', 'stores'));
        self::assertSame([], $reader->keys('broken'));
    }

    public function testConfigFilesAreNeverExecuted(): void
    {
        $reader = $this->reader();
        self::assertSame('redis', $reader->get('cache', 'literal')->value);

        // The fixture config writes <project>/EXECUTED if it is ever included.
        self::assertSame([], glob($this->tempDir() . '/*/EXECUTED') ?: []);
    }
}
