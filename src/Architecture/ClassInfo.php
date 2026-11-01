<?php

declare(strict_types=1);

namespace LaraInspect\Architecture;

final readonly class ClassInfo
{
    public const KIND_CLASS = 'class';
    public const KIND_INTERFACE = 'interface';
    public const KIND_TRAIT = 'trait';
    public const KIND_ENUM = 'enum';

    /**
     * @param list<string> $interfaces Fully-qualified names.
     * @param list<string> $traits     Fully-qualified names.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $file,
        public ?string $parent,
        public array $interfaces,
        public array $traits,
        public int $lines,
        public int $publicMethods,
        public bool $abstract = false,
    ) {
    }

    public function shortName(): string
    {
        $parts = explode('\\', $this->name);

        return (string) end($parts);
    }

    public function inNamespace(string $segment): bool
    {
        return str_contains('\\' . $this->name . '\\', '\\' . $segment . '\\');
    }
}
