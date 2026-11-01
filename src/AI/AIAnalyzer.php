<?php

declare(strict_types=1);

namespace LaraInspect\AI;

use LaraInspect\AI\Contracts\AIProviderInterface;
use LaraInspect\AI\DTO\ChatRequest;
use LaraInspect\AI\DTO\ChatResponse;
use LaraInspect\AI\DTO\Usage;
use LaraInspect\AI\Exceptions\AIException;
use LaraInspect\AI\Tasks\AITask;
use LaraInspect\Model\AIInsight;
use LaraInspect\Model\InspectionReport;
use LaraInspect\Security\SecretRedactor;

/**
 * Runs AI tasks over a finished inspection report.
 *
 * The AI interprets results; it never adds findings. A failing task is
 * recorded with its error and does not stop the others.
 */
final class AIAnalyzer
{
    public function __construct(
        private readonly PromptBuilder $prompts,
        private readonly SecretRedactor $redactor,
    ) {
    }

    /**
     * @param list<AITask>                                $tasks
     * @param (callable(AITask): void)|null               $onStart
     * @return list<AIInsight>
     */
    public function run(InspectionReport $report, AIProviderInterface $provider, array $tasks, ?callable $onStart = null): array
    {
        $insights = [];
        foreach ($tasks as $task) {
            if ($onStart !== null) {
                $onStart($task);
            }
            [$request, $notes] = $this->prompts->build($report, $task);
            $insights[] = $this->runTask($provider, $task, $request, $notes);
        }

        return $insights;
    }

    /**
     * The exact requests that would be sent, for --dry-run.
     *
     * @param list<AITask> $tasks
     * @return list<array{task: AITask, request: ChatRequest, notes: list<string>}>
     */
    public function preview(InspectionReport $report, array $tasks): array
    {
        return array_map(function (AITask $task) use ($report): array {
            [$request, $notes] = $this->prompts->build($report, $task);

            return ['task' => $task, 'request' => $request, 'notes' => $notes];
        }, $tasks);
    }

    /**
     * @param list<string> $notes
     */
    private function runTask(AIProviderInterface $provider, AITask $task, ChatRequest $request, array $notes): AIInsight
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $model = $provider instanceof Providers\AbstractHttpProvider ? (string) $provider->config()->model : '';

        try {
            $response = $provider->chat($request);
        } catch (AIException $e) {
            return new AIInsight($task->key, $task->title, $provider->getProviderName(), $model, '', 'error', Usage::none(), $now, $this->redactor->redact($e->getMessage()), $notes);
        }

        if ($response->finishReason === ChatResponse::FINISH_REFUSAL) {
            $notes[] = sprintf('The model declined this task (%s).', $response->refusal ?? 'no reason given');
        } elseif ($response->finishReason === ChatResponse::FINISH_LENGTH) {
            $notes[] = 'The answer was cut off at the output token limit (AI_MAX_TOKENS).';
        } elseif ($response->finishReason === ChatResponse::FINISH_FILTERED) {
            $notes[] = 'The provider\'s content filter stopped the answer.';
        }

        return new AIInsight(
            $task->key,
            $task->title,
            $response->provider,
            $response->model,
            // Defence in depth: the model never saw secrets, but scrub its output anyway.
            $this->redactor->redact(trim($response->content)),
            $response->finishReason,
            $response->usage,
            $now,
            $response->finishReason === ChatResponse::FINISH_REFUSAL && trim($response->content) === '' ? 'The model declined the request.' : null,
            $notes,
        );
    }
}
