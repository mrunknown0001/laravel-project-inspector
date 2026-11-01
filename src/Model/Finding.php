<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Support\Arr;

final readonly class Finding
{
    /**
     * @param string $id        Stable rule identifier, e.g. "deps.abandoned".
     * @param string $component Affected file or component, relative to the project root.
     */
    public function __construct(
        public string $id,
        public string $title,
        public Severity $severity,
        public Confidence $confidence,
        public string $component,
        public string $explanation,
        public string $evidence,
        public string $remediation,
        public string $analyzer = '',
    ) {
    }

    public function withAnalyzer(string $analyzer): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->severity,
            $this->confidence,
            $this->component,
            $this->explanation,
            $this->evidence,
            $this->remediation,
            $analyzer,
        );
    }

    /**
     * @return array{id: string, title: string, severity: string, confidence: string, component: string, explanation: string, evidence: string, remediation: string, analyzer: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'component' => $this->component,
            'explanation' => $this->explanation,
            'evidence' => $this->evidence,
            'remediation' => $this->remediation,
            'analyzer' => $this->analyzer,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::string($data, 'id'),
            Arr::string($data, 'title'),
            Severity::fromName(Arr::string($data, 'severity', 'INFO')),
            Confidence::tryFrom(Arr::string($data, 'confidence')) ?? Confidence::Potential,
            Arr::string($data, 'component'),
            Arr::string($data, 'explanation'),
            Arr::string($data, 'evidence'),
            Arr::string($data, 'remediation'),
            Arr::string($data, 'analyzer'),
        );
    }
}
