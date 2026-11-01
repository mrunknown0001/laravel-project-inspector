<?php

declare(strict_types=1);

namespace LaraInspect\Parsing;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Model\Confidence;
use LaraInspect\Model\Source;

/**
 * Which environment the inspected configuration describes.
 *
 * Production-only checks run only when APP_ENV comes from an env file. If it
 * only comes from Laravel's config default ("production"), a repository
 * checkout without .env would look like production and produce noise.
 */
final readonly class EnvironmentProfile
{
    private const PRODUCTION_NAMES = ['production', 'prod', 'live'];

    public function __construct(
        public ?string $name,
        public Source $source,
        public string $origin,
    ) {
    }

    public static function fromContext(InspectionContext $context): self
    {
        $value = (new SettingResolver($context))->resolve('environment');

        return new self($value->asString(), $value->source, $value->origin);
    }

    public function fromEnvFile(): bool
    {
        return str_starts_with($this->origin, '.env');
    }

    public function isProduction(): bool
    {
        return $this->fromEnvFile() && $this->name !== null && in_array(strtolower($this->name), self::PRODUCTION_NAMES, true);
    }

    /**
     * Confirmed when APP_ENV came from .env itself; potential when from .env.example.
     */
    public function confidence(ConfigValue ...$inputs): Confidence
    {
        if ($this->source !== Source::Detected) {
            return Confidence::Potential;
        }
        foreach ($inputs as $input) {
            if ($input->source !== Source::Detected) {
                return Confidence::Potential;
            }
        }

        return Confidence::Confirmed;
    }

    public function describe(): string
    {
        return sprintf('APP_ENV=%s (%s)', $this->name ?? 'unknown', $this->origin);
    }
}
