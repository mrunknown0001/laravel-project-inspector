<?php

declare(strict_types=1);

namespace LaraInspect\AI\Tasks;

/**
 * The AI-assisted features. Every task interprets inspection results; none of
 * them is allowed to add findings of its own.
 */
final class TaskCatalog
{
    /** Run when no --ai-task is given: the three most broadly useful, to limit cost. */
    public const DEFAULT_TASKS = ['explain-findings', 'security-fixes', 'technical-debt'];

    /**
     * @return array<string, AITask>
     */
    public static function all(): array
    {
        $tasks = [
            new AITask(
                'explain-findings',
                'Findings explained',
                'Explain the inspection findings for a developer who is new to this project. Start with the most severe. For each important finding, say in plain language what it means, why it matters for this application, and what to verify first. Group related findings instead of repeating yourself, and briefly mention what the low-severity and informational findings have in common.',
            ),
            new AITask(
                'architecture',
                'Architecture review',
                'Describe how this application is structured, based on the architecture, route and environment results: its main components, how requests are likely handled, and the frontend and integration choices. Then point out the strengths and the risks of this structure, and suggest at most five concrete improvements that fit its current shape. Do not recommend restructuring for its own sake.',
                ['environment', 'architecture', 'routes', 'database'],
            ),
            new AITask(
                'dependencies',
                'Dependency analysis',
                'Explain the dependency situation: lock file problems, PHP version conflicts, abandoned and outdated packages, and security advisories. Explain each conflict in terms of what will fail and when, and propose an upgrade order that keeps the application working (for example which packages must move together with the Laravel version).',
                ['environment', 'dependencies'],
            ),
            new AITask(
                'security-fixes',
                'Security remediation plan',
                'Turn the security-relevant findings into a prioritised remediation plan. For each item give the concrete Laravel-specific fix (configuration change, middleware, code pattern or command), how to verify it, and whether it needs a credential rotation. Treat "potential" findings as things to confirm first. Finish with the order you would do them in during one working day.',
                ['security', 'dependencies', 'configuration', 'routes', 'environment'],
            ),
            new AITask(
                'documentation',
                'Project documentation draft',
                'Write a concise technical overview of this project for its README or onboarding wiki: technology stack and versions, application structure, main route areas, data storage, queues and integrations, required environment settings (names only), and how quality is checked. Mark anything that is inferred rather than detected. Do not include secrets or placeholder credentials.',
                ['environment', 'dependencies', 'routes', 'configuration', 'database', 'architecture', 'quality'],
            ),
            new AITask(
                'technical-debt',
                'Technical debt summary',
                'Summarise the technical debt visible in these results (framework and dependency age, configuration, structure, tests and tooling, security hygiene). Group it into themes, estimate the relative effort (small, medium, large) and the risk of leaving each theme alone, and propose a short, ordered plan.',
            ),
            new AITask(
                'best-practices',
                'Laravel best practices',
                'Give Laravel best-practice recommendations that apply to this specific project, taking its Laravel version, packages and detected structure into account. Prefer a few high-value recommendations over a generic checklist, and say which inspection result each one is based on.',
            ),
        ];

        $byKey = [];
        foreach ($tasks as $task) {
            $byKey[$task->key] = $task;
        }

        return $byKey;
    }

    /**
     * @param list<string> $keys Task keys, or ["all"]; empty = defaults.
     * @return list<AITask>
     */
    public static function select(array $keys): array
    {
        $all = self::all();
        if ($keys === []) {
            $keys = self::DEFAULT_TASKS;
        }
        if (in_array('all', $keys, true)) {
            return array_values($all);
        }
        $unknown = array_diff($keys, array_keys($all));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown AI task%s: %s. Available: %s, all.', count($unknown) > 1 ? 's' : '', implode(', ', $unknown), implode(', ', array_keys($all))));
        }

        return array_values(array_map(static fn (string $k): AITask => $all[$k], array_values(array_unique($keys))));
    }
}
