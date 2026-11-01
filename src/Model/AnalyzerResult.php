<?php

declare(strict_types=1);

namespace LaraInspect\Model;

use LaraInspect\Support\Arr;

final readonly class AnalyzerResult
{
    /**
     * @param list<Fact>           $facts
     * @param list<Table>          $tables
     * @param list<Finding>        $findings
     * @param list<string>         $notes    Caveats about what could not be checked.
     * @param array<string, mixed> $data     Machine-readable details for JSON consumers and AI prompts.
     */
    public function __construct(
        public string $analyzer,
        public string $title,
        public ResultStatus $status = ResultStatus::Completed,
        public array $facts = [],
        public array $tables = [],
        public array $findings = [],
        public array $notes = [],
        public array $data = [],
    ) {
    }

    public static function failed(string $analyzer, string $title, string $reason): self
    {
        return new self($analyzer, $title, ResultStatus::Failed, notes: [$reason]);
    }

    public static function skipped(string $analyzer, string $title, string $reason): self
    {
        return new self($analyzer, $title, ResultStatus::Skipped, notes: [$reason]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'analyzer' => $this->analyzer,
            'title' => $this->title,
            'status' => $this->status->value,
            'facts' => array_map(static fn (Fact $f): array => $f->toArray(), $this->facts),
            'tables' => array_map(static fn (Table $t): array => $t->toArray(), $this->tables),
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
            'notes' => $this->notes,
            'data' => $this->data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $extra */
        $extra = Arr::array($data, 'data');

        return new self(
            Arr::string($data, 'analyzer'),
            Arr::string($data, 'title'),
            ResultStatus::tryFrom(Arr::string($data, 'status')) ?? ResultStatus::Completed,
            array_map(Fact::fromArray(...), Arr::listOfArrays($data, 'facts')),
            array_map(Table::fromArray(...), Arr::listOfArrays($data, 'tables')),
            array_map(Finding::fromArray(...), Arr::listOfArrays($data, 'findings')),
            Arr::strings($data, 'notes'),
            $extra,
        );
    }
}
