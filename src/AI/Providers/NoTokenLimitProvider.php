<?php

declare(strict_types=1);

namespace LaraInspect\AI\Providers;

/**
 * NoTokenLimit (https://notokenlimit.com/api/v1).
 *
 * NoTokenLimit's API documentation could not be retrieved (its docs pages
 * refuse automated access). This adapter follows the behaviour observed on
 * 2026-11-01: an OpenAI-compatible /chat/completions endpoint with Bearer
 * "ntl_live_" keys, a public /models list with "name"/"tier"/"description",
 * and errors shaped {"error": {"type", "code", "message"}}. Everything else
 * is inherited from the OpenAI-compatible base.
 */
final class NoTokenLimitProvider extends OpenAICompatibleProvider
{
    public function validateConfiguration(): array
    {
        $problems = parent::validateConfiguration();
        if ($this->config->hasApiKey() && !str_starts_with((string) $this->config->apiKey, 'ntl_')) {
            $problems[] = 'NOTOKENLIMIT_API_KEY does not look like a NoTokenLimit key (they start with "ntl_live_").';
        }

        return $problems;
    }
}
