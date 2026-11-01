<?php

declare(strict_types=1);

/*
 * AI provider definitions. Credentials are never stored here: they come from
 * environment variables or the inspector's own .env file (see .env.example).
 *
 * default_model is used only when neither <PREFIX>_MODEL nor AI_MODEL is set;
 * null means the user must choose a model (list them with "larainspect ai:models <provider>").
 */
return [
    'anthropic' => [
        'label' => 'Anthropic',
        'class' => LaraInspect\AI\Providers\AnthropicProvider::class,
        'env_prefix' => 'ANTHROPIC',
        'base_url' => 'https://api.anthropic.com',
        'default_model' => 'claude-opus-5-5',
        'docs' => 'https://platform.claude.com/docs/en/api/messages',
    ],
    'openai' => [
        'label' => 'OpenAI',
        'class' => LaraInspect\AI\Providers\OpenAIProvider::class,
        'env_prefix' => 'OPENAI',
        'base_url' => 'https://api.openai.com/v1',
        'default_model' => null,
        'docs' => 'https://platform.openai.com/docs/api-reference/chat',
    ],
    'openrouter' => [
        'label' => 'OpenRouter',
        'class' => LaraInspect\AI\Providers\OpenRouterProvider::class,
        'env_prefix' => 'OPENROUTER',
        'base_url' => 'https://openrouter.ai/api/v1',
        'default_model' => null,
        'docs' => 'https://openrouter.ai/docs/api-reference/overview',
    ],
    'notokenlimit' => [
        'label' => 'NoTokenLimit',
        'class' => LaraInspect\AI\Providers\NoTokenLimitProvider::class,
        'env_prefix' => 'NOTOKENLIMIT',
        'base_url' => 'https://notokenlimit.com/api/v1',
        'default_model' => null,
        'docs' => 'https://notokenlimit.com/integrations',
        'note' => 'Third-party reseller, not affiliated with the model vendors. Its API documentation could not be retrieved; the adapter follows the observed OpenAI-compatible behaviour.',
    ],
];
