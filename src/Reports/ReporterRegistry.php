<?php

declare(strict_types=1);

namespace LaraInspect\Reports;

use LaraInspect\Contracts\ReporterInterface;

final class ReporterRegistry
{
    public const CONSOLE = 'console';

    private const ALIASES = ['md' => 'markdown', 'htm' => 'html', 'terminal' => 'console', 'text' => 'console'];

    /** @var array<string, ReporterInterface> */
    private array $reporters = [];

    /**
     * @param iterable<ReporterInterface> $reporters
     */
    public function __construct(iterable $reporters = [])
    {
        foreach ($reporters as $reporter) {
            $this->reporters[$reporter->format()] = $reporter;
        }
    }

    public static function default(): self
    {
        return new self([new MarkdownReporter(), new HtmlReporter(), new JsonReporter()]);
    }

    /**
     * @return list<string> Every accepted --format value, console first.
     */
    public function formats(): array
    {
        return [self::CONSOLE, ...array_keys($this->reporters)];
    }

    /**
     * Normalises a --format value; throws for unknown formats.
     */
    public function normalise(string $format): string
    {
        $format = strtolower(trim($format));
        $format = self::ALIASES[$format] ?? $format;
        if ($format !== self::CONSOLE && !isset($this->reporters[$format])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown format "%s". Use one of: %s.',
                $format,
                implode(', ', $this->formats()),
            ));
        }

        return $format;
    }

    public function get(string $format): ReporterInterface
    {
        $format = $this->normalise($format);

        return $this->reporters[$format]
            ?? throw new \InvalidArgumentException('The console format is rendered directly, not to a file.');
    }
}
