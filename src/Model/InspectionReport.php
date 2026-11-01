<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Core\ProjectInfo;
use LaraInspect\Support\Arr;

/**
 * Everything an inspection produced. Reporters render this; nothing else.
 */
final readonly class InspectionReport
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<AnalyzerResult> $results
     * @param list<AuditEntry>     $audit
     * @param list<AIInsight>      $insights AI-generated interpretation; never findings.
     */
    public function __construct(
        public string $inspectorVersion,
        public \DateTimeImmutable $generatedAt,
        public ProjectInfo $project,
        public array $results,
        public array $audit = [],
        public int $durationMs = 0,
        public bool $offline = false,
        public array $insights = [],
    ) {
    }

    /**
     * @param list<AIInsight> $insights
     * @param list<AuditEntry> $audit
     */
    public function withInsights(array $insights, array $audit): self
    {
        return new self($this->inspectorVersion, $this->generatedAt, $this->project, $this->results, $audit, $this->durationMs, $this->offline, $insights);
    }

    /**
     * All findings, most severe first. Order within a severity follows analyzer order.
     *
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];
        foreach ($this->results as $result) {
            array_push($findings, ...$result->findings);
        }
        // usort is stable since PHP 8.0, so analyzer order is preserved within a severity.
        usort($findings, static fn (Finding $a, Finding $b): int => $b->severity->weight() <=> $a->severity->weight());

        return $findings;
    }

    /**
     * @return array<string, int> Severity value => count, for every severity.
     */
    public function severityCounts(): array
    {
        $counts = array_fill_keys(array_map(static fn (Severity $s): string => $s->value, Severity::ordered()), 0);
        foreach ($this->findings() as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function highestSeverity(): ?Severity
    {
        return ($this->findings()[0] ?? null)?->severity;
    }

    public function result(string $analyzer): ?AnalyzerResult
    {
        foreach ($this->results as $result) {
            if ($result->analyzer === $analyzer) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'inspector_version' => $this->inspectorVersion,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'duration_ms' => $this->durationMs,
            'offline' => $this->offline,
            'project' => $this->project->toArray(),
            'summary' => [
                'findings' => count($this->findings()),
                'by_severity' => $this->severityCounts(),
                'analyzers' => array_map(
                    static fn (AnalyzerResult $r): array => ['analyzer' => $r->analyzer, 'status' => $r->status->value],
                    $this->results,
                ),
            ],
            'results' => array_map(static fn (AnalyzerResult $r): array => $r->toArray(), $this->results),
            'audit' => array_map(static fn (AuditEntry $e): array => $e->toArray(), $this->audit),
            'ai' => array_map(static fn (AIInsight $i): array => $i->toArray(), $this->insights),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $schema = Arr::int($data, 'schema_version');
        if ($schema !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported report schema version %d (expected %d).',
                $schema,
                self::SCHEMA_VERSION,
            ));
        }

        try {
            $generatedAt = new \DateTimeImmutable(Arr::string($data, 'generated_at', 'now'));
        } catch (\Exception) {
            throw new \InvalidArgumentException('Report has an invalid "generated_at" timestamp.');
        }

        /** @var array<string, mixed> $project */
        $project = Arr::array($data, 'project');

        return new self(
            Arr::string($data, 'inspector_version'),
            $generatedAt,
            ProjectInfo::fromArray($project),
            array_map(AnalyzerResult::fromArray(...), Arr::listOfArrays($data, 'results')),
            array_map(AuditEntry::fromArray(...), Arr::listOfArrays($data, 'audit')),
            Arr::int($data, 'duration_ms'),
            Arr::bool($data, 'offline'),
            array_map(AIInsight::fromArray(...), Arr::listOfArrays($data, 'ai')),
        );
    }
}
