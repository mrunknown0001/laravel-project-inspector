<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

/**
 * Attributes inherited from enclosing Route::group() / registrar chains.
 */
final readonly class GroupAttributes
{
    /**
     * @param list<string> $middleware
     * @param list<string> $withoutMiddleware
     */
    public function __construct(
        public string $prefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public array $withoutMiddleware = [],
        public ?string $namespace = null,
        public ?string $controller = null,
        public ?string $domain = null,
    ) {
    }

    public function withPrefix(string $prefix): self
    {
        return new self(RouteUri::join($this->prefix, $prefix), $this->namePrefix, $this->middleware, $this->withoutMiddleware, $this->namespace, $this->controller, $this->domain);
    }

    public function withNamePrefix(string $name): self
    {
        return new self($this->prefix, $this->namePrefix . $name, $this->middleware, $this->withoutMiddleware, $this->namespace, $this->controller, $this->domain);
    }

    /**
     * @param list<string> $middleware
     */
    public function withMiddleware(array $middleware): self
    {
        return new self($this->prefix, $this->namePrefix, [...$this->middleware, ...$middleware], $this->withoutMiddleware, $this->namespace, $this->controller, $this->domain);
    }

    /**
     * @param list<string> $middleware
     */
    public function withoutMiddleware(array $middleware): self
    {
        return new self($this->prefix, $this->namePrefix, $this->middleware, [...$this->withoutMiddleware, ...$middleware], $this->namespace, $this->controller, $this->domain);
    }

    public function withNamespace(string $namespace): self
    {
        $namespace = str_starts_with($namespace, '\\') || $this->namespace === null
            ? trim($namespace, '\\')
            : trim($this->namespace . '\\' . $namespace, '\\');

        return new self($this->prefix, $this->namePrefix, $this->middleware, $this->withoutMiddleware, $namespace, $this->controller, $this->domain);
    }

    public function withController(string $controller): self
    {
        return new self($this->prefix, $this->namePrefix, $this->middleware, $this->withoutMiddleware, $this->namespace, $controller, $this->domain);
    }

    public function withDomain(string $domain): self
    {
        return new self($this->prefix, $this->namePrefix, $this->middleware, $this->withoutMiddleware, $this->namespace, $this->controller, $domain);
    }

    /**
     * Applies group namespace to a controller referenced by string (pre-Laravel 8 style).
     */
    public function qualify(string $controller): string
    {
        if (str_starts_with($controller, '\\') || $this->namespace === null || str_contains(ltrim($controller, '\\'), '\\')) {
            return ltrim($controller, '\\');
        }

        return $this->namespace . '\\' . $controller;
    }
}
