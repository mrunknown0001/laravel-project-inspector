<?php

declare(strict_types=1);

namespace LaraInspect\Core;

use LaraInspect\Contracts\AnalyzerInterface;

final class AnalyzerRegistry
{
    /** @var array<string, AnalyzerInterface> */
    private array $analyzers = [];

    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(iterable $analyzers = [])
    {
        foreach ($analyzers as $analyzer) {
            $this->register($analyzer);
        }
    }

    public function register(AnalyzerInterface $analyzer): void
    {
        $name = $analyzer->name();
        if (isset($this->analyzers[$name])) {
            throw new \LogicException(sprintf('An analyzer named "%s" is already registered.', $name));
        }
        $this->analyzers[$name] = $analyzer;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->analyzers);
    }

    public function get(string $name): AnalyzerInterface
    {
        return $this->analyzers[$name]
            ?? throw new \InvalidArgumentException($this->unknownMessage([$name]));
    }

    /**
     * Applies --only / --exclude, preserving registration order.
     *
     * @param list<string> $only
     * @param list<string> $exclude
     * @return list<AnalyzerInterface>
     *
     * @throws \InvalidArgumentException When a name is unknown or nothing is left to run.
     */
    public function select(array $only = [], array $exclude = []): array
    {
        $unknown = array_diff([...$only, ...$exclude], $this->names());
        if ($unknown !== []) {
            throw new \InvalidArgumentException($this->unknownMessage(array_values($unknown)));
        }

        $selected = array_filter(
            $this->analyzers,
            static fn (string $name): bool => ($only === [] || in_array($name, $only, true)) && !in_array($name, $exclude, true),
            ARRAY_FILTER_USE_KEY,
        );
        if ($selected === []) {
            throw new \InvalidArgumentException('No analyzers left to run after applying --only/--exclude.');
        }

        return array_values($selected);
    }

    /**
     * @param list<string> $names
     */
    private function unknownMessage(array $names): string
    {
        return sprintf(
            'Unknown analyzer%s: %s. Available: %s.',
            count($names) > 1 ? 's' : '',
            implode(', ', $names),
            implode(', ', $this->names()),
        );
    }
}
