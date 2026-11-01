<?php

declare(strict_types=1);

namespace LaraInspect\AI;

use LaraInspect\AI\DTO\ChatMessage;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\Tasks\AITask;
use LaraInspect\Model\AnalyzerResult;
use LaraInspect\Model\Fact;
use LaraInspect\Model\Finding;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Model\Severity;
use LaraInspect\Security\SecretRedactor;

/**
 * Turns an inspection report into a chat request for one task.
 *
 * Only inspection results are sent: findings, facts, notes and a whitelist of
 * summary data. Never source code, .env contents, the absolute project path
 * or the audit log. The final prompt is redacted again before it leaves.
 */
final class PromptBuilder
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
        You are a senior Laravel engineer reviewing the results of an automated, read-only inspection of a Laravel application. You receive the inspection results as JSON, not the source code.

        - Base every statement on the provided data. Do not invent findings, files, packages, versions or vulnerabilities that are not in it.
        - Each finding has a confidence: "confirmed" findings were verified from evidence; "potential" ones need review. Keep that distinction, and say so when you infer something.
        - Refer to findings by their rule id in backticks, for example `sec.debug-in-production`.
        - Secrets were removed before sending ([REDACTED] or masked values). Never guess or ask for credentials.
        - Answer in concise GitHub-flavoured Markdown with short sections, without a preamble.
        PROMPT;

    /** Per-analyzer data keys considered safe and useful as context. */
    private const DATA_WHITELIST = [
        'environment' => ['laravel', 'php', 'frontend', 'drivers', 'database_technology', 'app'],
        'dependencies' => ['require', 'require_dev', 'abandoned', 'advisory_count', 'outdated'],
        'routes' => ['source', 'counts'],
        'configuration' => ['environment', 'settings', 'cors'],
        'database' => ['technology', 'migrations', 'tables', 'foreign_keys', 'indexes', 'packages'],
        'architecture' => ['overview', 'components', 'patterns', 'frontend', 'auth_packages', 'integrations', 'services'],
        'quality' => ['tools', 'test_framework', 'test_files', 'ci', 'ci_tools'],
    ];

    /** Progressively smaller representations, tried until the context fits. */
    private const REDUCTION_LEVELS = [
        ['evidence' => 1500, 'max_findings' => 100, 'data' => true],
        ['evidence' => 400, 'max_findings' => 60, 'data' => true],
        ['evidence' => 150, 'max_findings' => 40, 'data' => true],
        ['evidence' => 0, 'max_findings' => 25, 'data' => false],
        ['evidence' => 0, 'max_findings' => 10, 'data' => false],
    ];

    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly int $maxInputChars = 60_000,
    ) {
    }

    /**
     * @return array{0: ChatRequest, 1: list<string>} the request and notes about reductions
     */
    public function build(InspectionReport $report, AITask $task, ?int $maxTokens = null): array
    {
        $notes = [];
        $json = '';
        foreach (self::REDUCTION_LEVELS as $index => $level) {
            $json = $this->context($report, $task, $level);
            if (mb_strlen($json) <= $this->maxInputChars) {
                if ($index > 0) {
                    $notes[] = sprintf('The inspection data was condensed to fit AI_MAX_INPUT_CHARS (%d characters): evidence was shortened and lower-severity findings may have been left out.', $this->maxInputChars);
                }
                break;
            }
            if ($index === array_key_last(self::REDUCTION_LEVELS)) {
                // Never cut JSON mid-structure; send the smallest form and say so.
                $notes[] = sprintf('The inspection data is still larger than AI_MAX_INPUT_CHARS (%d characters) after condensing; it was sent in its smallest form.', $this->maxInputChars);
            }
        }

        $user = sprintf("Task: %s\n\nInspection results (JSON):\n```json\n%s\n```", $task->instruction, $json);

        return [new ChatRequest([ChatMessage::system(self::SYSTEM_PROMPT), ChatMessage::user($user)], maxTokens: $maxTokens), $notes];
    }

    /**
     * @param array{evidence: int, max_findings: int, data: bool} $level
     */
    private function context(InspectionReport $report, AITask $task, array $level): string
    {
        $project = $report->project;
        $context = [
            'project' => [
                'name' => $project->name,
                'framework' => $project->framework,
                'laravel_version' => $project->laravelVersion,
                'layout' => $project->layout,
            ],
            'inspection' => [
                'inspector_version' => $report->inspectorVersion,
                'generated_at' => $report->generatedAt->format(\DateTimeInterface::ATOM),
                'network_checks' => !$report->offline,
                'severity_counts' => $report->severityCounts(),
            ],
            'analyzers' => [],
        ];

        foreach ($report->results as $result) {
            if ($task->analyzers !== [] && !in_array($result->analyzer, $task->analyzers, true)) {
                continue;
            }
            $context['analyzers'][] = $this->analyzer($result, $level);
        }

        $json = (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // The absolute path can contain a user name; it is never needed.
        $json = str_replace([$project->root, str_replace('/', '\\/', $project->root)], '<project>', $json);

        return $this->redactor->redact($json);
    }

    /**
     * @param array{evidence: int, max_findings: int, data: bool} $level
     * @return array<string, mixed>
     */
    private function analyzer(AnalyzerResult $result, array $level): array
    {
        $findings = $result->findings;
        usort($findings, static fn (Finding $a, Finding $b): int => $b->severity->weight() <=> $a->severity->weight());
        $omitted = max(0, count($findings) - $level['max_findings']);
        $findings = array_slice($findings, 0, $level['max_findings']);

        $entry = [
            'analyzer' => $result->analyzer,
            'title' => $result->title,
            'status' => $result->status->value,
            'facts' => array_map(static fn (Fact $f): array => ['label' => $f->label, 'value' => $f->value, 'source' => $f->source->value], $result->facts),
            'findings' => array_map(static fn (Finding $f): array => array_filter([
                'id' => $f->id,
                'severity' => $f->severity->value,
                'confidence' => $f->confidence->value,
                'title' => $f->title,
                'component' => $f->component,
                'explanation' => $f->explanation,
                'evidence' => $level['evidence'] > 0 && !($level['evidence'] < 400 && $f->severity->weight() <= Severity::Low->weight())
                    ? mb_substr($f->evidence, 0, $level['evidence'])
                    : null,
                'remediation' => $f->remediation,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''), $findings),
            'notes' => array_map(static fn (string $n): string => mb_substr($n, 0, 300), $result->notes),
        ];
        if ($omitted > 0) {
            $entry['findings_omitted'] = $omitted;
        }
        if ($level['data']) {
            $data = array_intersect_key($result->data, array_flip(self::DATA_WHITELIST[$result->analyzer] ?? []));
            if ($data !== []) {
                $entry['data'] = $data;
            }
        }

        return $entry;
    }
}
