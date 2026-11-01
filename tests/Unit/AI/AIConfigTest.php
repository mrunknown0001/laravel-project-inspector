<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Unit\AI;

use LaraInspect\AI\AIConfig;
use LaraInspect\AI\AIManager;
use LaraInspect\AI\Exceptions\ConfigurationException;
use LaraInspect\AI\Providers\AnthropicProvider;
use LaraInspect\AI\Providers\NoTokenLimitProvider;
use LaraInspect\AI\Providers\OpenAIProvider;
use LaraInspect\AI\Providers\OpenRouterProvider;
use LaraInspect\AI\Tasks\TaskCatalog;
use LaraInspect\Tests\Support\RecordingHttpClient;
use LaraInspect\Tests\Support\UsesTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AIConfigTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @param array<string, string> $env
     * @param array<string, string> $file
     */
    private static function config(array $env = [], array $file = []): AIConfig
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = require dirname(__DIR__, 3) . '/config/providers.php';

        return new AIConfig($providers, $env, $file);
    }

    public function testEnvironmentOverridesFileOverridesDefaults(): void
    {
        $config = self::config(['AI_TIMEOUT' => '90'], ['AI_TIMEOUT' => '45', 'AI_MAX_TOKENS' => '4000']);

        self::assertSame(['90', AIConfig::SOURCE_ENVIRONMENT], $config->value('AI_TIMEOUT'));
        self::assertSame(['4000', AIConfig::SOURCE_FILE], $config->value('AI_MAX_TOKENS'));
        self::assertSame(['2', AIConfig::SOURCE_DEFAULT], $config->value('AI_MAX_RETRIES'));
        self::assertFalse($config->isEnabled());
        self::assertTrue(self::config(['AI_ENABLED' => 'true'])->isEnabled());
    }

    public function testModelPrecedence(): void
    {
        $config = self::config(['AI_PROVIDER' => 'openrouter', 'AI_MODEL' => 'anthropic/claude-opus-5.5', 'OPENAI_MODEL' => 'gpt-test']);

        self::assertSame('anthropic/claude-opus-5.5', $config->provider('openrouter')->model, 'AI_MODEL applies to AI_PROVIDER');
        self::assertSame('gpt-test', $config->provider('openai')->model, '<PREFIX>_MODEL');
        self::assertSame('claude-opus-5-5', $config->provider('anthropic')->model, 'AI_MODEL does not leak into other providers; the default applies.');
        self::assertNull($config->provider('notokenlimit')->model);
        self::assertSame('override', $config->provider('openai', 'override')->model, '--model wins');
    }

    public function testProviderSettingsAndOptions(): void
    {
        $config = self::config(['ANTHROPIC_API_KEY' => 'sk-ant-test', 'ANTHROPIC_BASE_URL' => 'https://proxy.test/', 'ANTHROPIC_EFFORT' => 'low', 'AI_MAX_RETRIES' => '9', 'AI_TIMEOUT' => '1']);
        $anthropic = $config->provider('ANTHROPIC');

        self::assertSame('sk-ant-test', $anthropic->apiKey);
        self::assertSame('https://proxy.test', $anthropic->baseUrl);
        self::assertSame('low', $anthropic->option('EFFORT'));
        self::assertSame(5, $anthropic->maxRetries, 'Retries are capped.');
        self::assertSame(5.0, $anthropic->timeout, 'Timeouts have a floor.');
        self::assertSame('https://openrouter.ai/api/v1', $config->provider('openrouter')->baseUrl);
    }

    public function testLoadsTheInspectorEnvFileWithoutInterpolation(): void
    {
        $base = $this->makeProject([
            'config/providers.php' => (string) file_get_contents(dirname(__DIR__, 3) . '/config/providers.php'),
            '.env' => "AI_PROVIDER=openai\nOPENAI_API_KEY=\"sk-from-file-123\"\nOPENAI_MODEL=\$(whoami)\n",
        ]);
        $config = AIConfig::load($base, []);

        self::assertSame('openai', $config->defaultProvider());
        self::assertSame('sk-from-file-123', $config->provider('openai')->apiKey);
        self::assertSame('$(whoami)', $config->provider('openai')->model);
    }

    public function testSettingsFileResolutionOrder(): void
    {
        $home = $this->tempDir() . '/home';
        $base = $this->makeProject(['config/providers.php' => (string) file_get_contents(dirname(__DIR__, 3) . '/config/providers.php')]);
        $env = ['HOME' => $home];

        self::assertNull(AIConfig::settingsFile($base, $env));
        self::assertSame($home . '/.config/larainspect/.env', AIConfig::userSettingsPath($env));
        self::assertSame('/xdg/larainspect/.env', AIConfig::userSettingsPath(['HOME' => $home, 'XDG_CONFIG_HOME' => '/xdg']));

        mkdir($home . '/.config/larainspect', 0o700, true);
        file_put_contents($home . '/.config/larainspect/.env', "AI_PROVIDER=openrouter\n");
        self::assertSame($home . '/.config/larainspect/.env', AIConfig::settingsFile($base, $env), 'Per-user file as the fallback.');
        self::assertSame('openrouter', AIConfig::load($base, $env)->defaultProvider());

        file_put_contents($base . '/.env', "AI_PROVIDER=openai\n");
        self::assertSame($base . '/.env', AIConfig::settingsFile($base, $env), 'The inspector directory wins over the per-user file.');

        $explicit = $this->tempDir() . '/custom.env';
        file_put_contents($explicit, "AI_PROVIDER=anthropic\n");
        $loaded = AIConfig::load($base, $env + ['LARAINSPECT_ENV_FILE' => $explicit]);
        self::assertSame('anthropic', $loaded->defaultProvider(), 'LARAINSPECT_ENV_FILE wins.');
        self::assertSame($explicit, $loaded->settingsFilePath());

        self::assertTrue(AIConfig::installedAsDependency('/home/me/.composer/vendor/larainspect/laravel-project-inspector'));
        self::assertFalse(AIConfig::installedAsDependency('/home/me/tools/laravel-project-inspector'));
    }

    public function testManagerResolvesProviders(): void
    {
        $manager = new AIManager(self::config(['AI_PROVIDER' => 'notokenlimit']), (new RecordingHttpClient())->client);

        self::assertInstanceOf(NoTokenLimitProvider::class, $manager->provider());
        self::assertInstanceOf(AnthropicProvider::class, $manager->provider('anthropic'));
        self::assertInstanceOf(OpenAIProvider::class, $manager->provider('openai'));
        self::assertInstanceOf(OpenRouterProvider::class, $manager->provider('OpenRouter'));
        self::assertSame(['anthropic', 'openai', 'openrouter', 'notokenlimit'], $manager->providerNames());

        try {
            (new AIManager(self::config()))->provider();
            self::fail('Expected a missing-provider error.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('No AI provider selected', $e->getMessage());
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown AI provider "gemini"');
        $manager->provider('gemini');
    }

    public function testManagerRejectsInvalidAdapterClasses(): void
    {
        $manager = new AIManager(new AIConfig(['broken' => ['class' => \stdClass::class]], []));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('no valid adapter class');
        $manager->provider('broken');
    }

    public function testDescribeListsEverySettingWithItsSource(): void
    {
        $rows = self::config(['OPENAI_API_KEY' => 'sk-x'])->describe();
        $keys = array_column($rows, 0);

        self::assertContains('AI_PROVIDER', $keys);
        self::assertContains('ANTHROPIC_VERSION', $keys);
        self::assertContains('OPENROUTER_APP_NAME', $keys);
        self::assertContains(['OPENAI_API_KEY', 'sk-x', AIConfig::SOURCE_ENVIRONMENT], $rows);
    }

    public function testTaskSelection(): void
    {
        self::assertSame(TaskCatalog::DEFAULT_TASKS, array_map(static fn ($t) => $t->key, TaskCatalog::select([])));
        self::assertCount(7, TaskCatalog::select(['all']));
        self::assertSame(['documentation'], array_map(static fn ($t) => $t->key, TaskCatalog::select(['documentation', 'documentation'])));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI task: poetry');
        TaskCatalog::select(['poetry']);
    }
}
