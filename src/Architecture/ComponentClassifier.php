<?php

declare(strict_types=1);

namespace LaraInspect\Architecture;

/**
 * Assigns each class to a Laravel component type: by inheritance and
 * interfaces first, then by namespace and naming convention.
 */
final class ComponentClassifier
{
    /**
     * Ordered: the first matching rule wins.
     * [component, ancestor/interface names (any), namespace segments (any), class-name suffixes (any)]
     *
     * @var list<array{0: string, 1: list<string>, 2: list<string>, 3: list<string>}>
     */
    private const RULES = [
        ['Livewire components', ['Livewire\\Component'], ['Livewire'], []],
        ['Filament resources', ['Filament\\Resources\\Resource'], [], []],
        ['Filament pages & widgets', ['Filament\\Pages\\Page', 'Filament\\Widgets\\Widget', 'Filament\\Resources\\Pages\\Page'], [], []],
        ['Filament panel providers', ['Filament\\PanelProvider'], [], []],
        ['Nova resources', ['Laravel\\Nova\\Resource'], [], []],
        ['Form requests', ['Illuminate\\Foundation\\Http\\FormRequest'], ['Requests'], ['Request']],
        ['API resources', ['Illuminate\\Http\\Resources\\Json\\JsonResource', 'Illuminate\\Http\\Resources\\Json\\ResourceCollection'], ['Resources'], ['Resource', 'Collection']],
        ['Controllers', ['Illuminate\\Routing\\Controller'], ['Controllers'], ['Controller']],
        ['Middleware', [], ['Middleware'], []],
        ['Models', ['Illuminate\\Database\\Eloquent\\Model', 'Illuminate\\Foundation\\Auth\\User', 'Illuminate\\Database\\Eloquent\\Relations\\Pivot', 'MongoDB\\Laravel\\Eloquent\\Model', 'Jenssegers\\Mongodb\\Eloquent\\Model'], ['Models'], []],
        ['Notifications', ['Illuminate\\Notifications\\Notification'], ['Notifications'], ['Notification']],
        ['Mailables', ['Illuminate\\Mail\\Mailable'], ['Mail'], ['Mail']],
        ['Jobs', ['Illuminate\\Contracts\\Queue\\ShouldQueue'], ['Jobs'], ['Job']],
        ['Events', [], ['Events'], ['Event']],
        ['Listeners', [], ['Listeners'], ['Listener']],
        ['Policies', [], ['Policies'], ['Policy']],
        ['Service providers', ['Illuminate\\Support\\ServiceProvider'], ['Providers'], ['ServiceProvider']],
        ['Console commands', ['Illuminate\\Console\\Command'], ['Commands'], ['Command']],
        ['Observers', [], ['Observers'], ['Observer']],
        ['Validation rules', ['Illuminate\\Contracts\\Validation\\ValidationRule', 'Illuminate\\Contracts\\Validation\\Rule', 'Illuminate\\Contracts\\Validation\\InvokableRule'], ['Rules'], ['Rule']],
        ['Casts', ['Illuminate\\Contracts\\Database\\Eloquent\\CastsAttributes'], ['Casts'], ['Cast']],
        ['View components', ['Illuminate\\View\\Component'], ['View\\Components'], []],
        ['Exceptions', ['Exception', 'RuntimeException', 'Throwable'], ['Exceptions'], ['Exception']],
        ['Repositories', [], ['Repositories', 'Repository'], ['Repository']],
        ['Services', [], ['Services', 'Service'], ['Service']],
        ['Actions', [], ['Actions'], ['Action']],
        ['Data objects', ['Spatie\\LaravelData\\Data'], ['DTOs', 'DataTransferObjects', 'DTO', 'Data'], ['DTO', 'Data']],
    ];

    public const OTHER = 'Other classes';

    public function classify(ClassInfo $class, ClassIndex $index): string
    {
        if ($class->kind === ClassInfo::KIND_ENUM) {
            return 'Enums';
        }
        if ($class->kind === ClassInfo::KIND_INTERFACE) {
            return 'Interfaces (contracts)';
        }
        if ($class->kind === ClassInfo::KIND_TRAIT) {
            return 'Traits';
        }

        $types = [...$index->ancestors($class), ...$index->allInterfaces($class)];
        foreach (self::RULES as [$component, $parents, $namespaces, $suffixes]) {
            if ($this->matchesAny($types, $parents)) {
                return $component;
            }
        }
        foreach (self::RULES as [$component, , $namespaces, $suffixes]) {
            foreach ($namespaces as $segment) {
                if ($class->inNamespace($segment)) {
                    return $component;
                }
            }
        }
        foreach (self::RULES as [$component, , , $suffixes]) {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($class->shortName(), $suffix) && $class->shortName() !== $suffix) {
                    return $component;
                }
            }
        }

        return self::OTHER;
    }

    /**
     * @param list<string> $types
     * @param list<string> $expected
     */
    private function matchesAny(array $types, array $expected): bool
    {
        foreach ($types as $type) {
            if (in_array(ltrim($type, '\\'), $expected, true)) {
                return true;
            }
        }

        return false;
    }
}
