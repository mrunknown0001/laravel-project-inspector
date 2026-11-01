<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

use LaraInspect\Support\Arr;

final readonly class RouteDefinition
{
    public const SOURCE_STATIC = 'static';
    public const SOURCE_ARTISAN = 'artisan';

    /**
     * @param list<string> $methods       Upper-case HTTP methods; ["ANY"] for any.
     * @param list<string> $middleware    Effective middleware (group + route + controller).
     * @param list<string> $closureCalls  Functions/static methods called inside a closure action.
     */
    public function __construct(
        public array $methods,
        public string $uri,
        public ?string $name,
        public string $action,
        public array $middleware,
        public string $file,
        public int $line,
        public string $source = self::SOURCE_STATIC,
        public ?string $controller = null,
        public ?string $controllerMethod = null,
        public bool $isClosure = false,
        public array $closureCalls = [],
        public bool $controllerMissing = false,
        public ?string $domain = null,
    ) {
    }

    public function methodLabel(): string
    {
        $methods = array_values(array_diff($this->methods, ['HEAD']));

        return implode('|', $methods === [] ? $this->methods : $methods);
    }

    public function isStateChanging(): bool
    {
        return in_array('ANY', $this->methods, true)
            || array_intersect($this->methods, ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
    }

    public function isApi(): bool
    {
        return in_array('api', $this->middleware, true) || $this->uri === 'api' || str_starts_with($this->uri, 'api/');
    }

    public function access(): string
    {
        return MiddlewareClassifier::access($this->middleware);
    }

    /**
     * Action with the default controller namespace trimmed, for display.
     */
    public function shortAction(): string
    {
        return str_replace('App\\Http\\Controllers\\', '', $this->action);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'uri' => $this->uri,
            'name' => $this->name,
            'action' => $this->action,
            'middleware' => $this->middleware,
            'access' => $this->access(),
            'file' => $this->file,
            'line' => $this->line,
            'source' => $this->source,
        ];
    }

    /**
     * @param array<string, mixed> $data A row of "artisan route:list --json".
     */
    public static function fromArtisan(array $data): self
    {
        $middleware = $data['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = preg_split('/[\r\n,]+/', $middleware) ?: [];
        }
        $middleware = array_values(array_filter(array_map(
            static fn (mixed $m): string => is_scalar($m) ? trim((string) $m) : '',
            is_array($middleware) ? $middleware : [],
        )));

        $action = Arr::string($data, 'action', 'Closure');
        [$controller, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action === 'Closure' ? null : $action, null];
        $name = Arr::string($data, 'name');
        $domain = Arr::string($data, 'domain');

        return new self(
            array_values(array_filter(explode('|', strtoupper(Arr::string($data, 'method', 'GET'))))),
            Arr::string($data, 'uri', '/'),
            $name === '' ? null : $name,
            $action,
            $middleware,
            'artisan route:list',
            0,
            self::SOURCE_ARTISAN,
            $controller,
            $method,
            $action === 'Closure',
            domain: $domain === '' ? null : $domain,
        );
    }
}
