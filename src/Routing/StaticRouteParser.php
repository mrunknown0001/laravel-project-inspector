<?php

declare(strict_types=1);

namespace LaraInspect\Routing;

use LaraInspect\Core\InspectionContext;
use LaraInspect\Parsing\PhpSource;
use LaraInspect\Support\ClassLocator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Reconstructs the route table from source, without booting the application.
 *
 * Entry points mirror how Laravel registers routes:
 *  - bootstrap/app.php ->withRouting(...) (Laravel 11+)
 *  - app/Providers/RouteServiceProvider.php (Laravel <= 10)
 *  - otherwise routes/web.php and routes/api.php with their default groups
 * Nested Route::group() calls, registrar chains, require/include of route
 * files, resources and controller groups are followed. Routes registered by
 * packages at runtime are not visible.
 */
final class StaticRouteParser
{
    private const VERBS = [
        'get' => ['GET', 'HEAD'], 'post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'],
        'delete' => ['DELETE'], 'options' => ['OPTIONS'], 'any' => ['ANY'],
    ];

    private const RESOURCE_ACTIONS = [
        'index' => [['GET', 'HEAD'], ''],
        'create' => [['GET', 'HEAD'], '/create'],
        'store' => [['POST'], ''],
        'show' => [['GET', 'HEAD'], '/{param}'],
        'edit' => [['GET', 'HEAD'], '/{param}/edit'],
        'update' => [['PUT', 'PATCH'], '/{param}'],
        'destroy' => [['DELETE'], '/{param}'],
    ];

    private const TERMINALS = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect', 'permanentredirect',
        'fallback', 'resource', 'apiresource', 'resources', 'apiresources', 'singleton', 'apisingleton', 'group',
    ];

    /** Dangerous or noteworthy calls recorded for closure actions. */
    private const MAX_CLOSURE_CALLS = 50;

    private PhpSource $php;
    private InspectionContext $context;
    private ControllerMiddlewareReader $controllers;
    private ClassLocator $locator;

    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> Files currently being walked, to stop include cycles. */
    private array $stack = [];

    /** @var array<string, string> Literal property defaults of the class being walked (for $this->namespace). */
    private array $properties = [];

    public function parse(InspectionContext $context): RouteCollection
    {
        $this->context = $context;
        $this->php = new PhpSource();
        $this->locator = new ClassLocator($context->files, $context->composer());
        $this->controllers = new ControllerMiddlewareReader($context->files, $this->locator, $this->php);
        $this->routes = $this->files = $this->notes = $this->stack = [];

        if (!$this->fromBootstrap() && !$this->fromRouteServiceProvider()) {
            $this->fromConventionalFiles();
        }

        $routes = array_map($this->withControllerMiddleware(...), $this->routes);
        $this->notes[] = 'Routes were read from source without booting the application. Routes registered by packages (e.g. Telescope, Horizon, Filament, Sanctum) and routes built dynamically are not included; use --use-artisan to list them.';

        return new RouteCollection($routes, RouteDefinition::SOURCE_STATIC, array_values(array_unique($this->files)), $this->notes);
    }

    private function fromBootstrap(): bool
    {
        $stmts = $this->php->parseFile($this->context->files, 'bootstrap/app.php');
        if ($stmts === null) {
            return false;
        }
        $call = (new NodeFinder())->findFirst($stmts, static fn (Node $n): bool => $n instanceof Expr\MethodCall
            && $n->name instanceof Node\Identifier && $n->name->toString() === 'withRouting');
        if (!$call instanceof Expr\MethodCall) {
            return false;
        }
        $this->files[] = 'bootstrap/app.php';
        $file = 'bootstrap/app.php';
        $root = new GroupAttributes();

        // withRouting(?Closure $using, $web, $api, $commands, $channels, $pages, $health, $apiPrefix, $then)
        $using = PhpSource::arg($call, 0, 'using');
        if ($using instanceof Expr\Closure || $using instanceof Expr\ArrowFunction) {
            $this->walkCallable($using, $root, $file);
        }

        $apiPrefix = PhpSource::string(PhpSource::arg($call, 7, 'apiPrefix')) ?? 'api';
        foreach ($this->pathList(PhpSource::arg($call, 1, 'web'), $file) as $web) {
            $this->walkFile($web, $root->withMiddleware(['web']));
        }
        foreach ($this->pathList(PhpSource::arg($call, 2, 'api'), $file) as $api) {
            $this->walkFile($api, $root->withMiddleware(['api'])->withPrefix($apiPrefix));
        }

        $health = PhpSource::string(PhpSource::arg($call, 6, 'health'));
        if ($health !== null) {
            $this->routes[] = new RouteDefinition(['GET', 'HEAD'], RouteUri::normalise($health), null, 'Health check (framework)', [], $file, $call->getStartLine());
        }
        if (PhpSource::arg($call, 5, 'pages') !== null) {
            $this->notes[] = 'Folio page routes (withRouting pages:) are not analysed.';
        }

        $then = PhpSource::arg($call, 8, 'then');
        if ($then instanceof Expr\Closure || $then instanceof Expr\ArrowFunction) {
            $this->walkCallable($then, $root, $file);
        }

        return true;
    }

    private function fromRouteServiceProvider(): bool
    {
        $file = 'app/Providers/RouteServiceProvider.php';
        $before = count($this->files);
        if (!$this->context->files->isFile($file)) {
            return false;
        }
        $this->walkFile($file, new GroupAttributes());

        // Only counts if the provider actually loaded route files.
        return count($this->files) - $before > 1;
    }

    private function fromConventionalFiles(): void
    {
        $found = false;
        if ($this->context->files->isFile('routes/web.php')) {
            $this->walkFile('routes/web.php', (new GroupAttributes())->withMiddleware(['web']));
            $found = true;
        }
        if ($this->context->files->isFile('routes/api.php')) {
            $this->walkFile('routes/api.php', (new GroupAttributes())->withMiddleware(['api'])->withPrefix('api'));
            $found = true;
        }
        if ($found) {
            $this->notes[] = 'No route registration was found in bootstrap/app.php or RouteServiceProvider; Laravel\'s default web and api groups were assumed.';
        }
    }

    private function walkFile(string $file, GroupAttributes $attributes): void
    {
        if (in_array($file, $this->stack, true)) {
            return;
        }
        if (!$this->context->files->isFile($file)) {
            $this->notes[] = sprintf('Route file %s is referenced but does not exist.', $file);

            return;
        }
        $stmts = $this->php->parseFile($this->context->files, $file);
        if ($stmts === null) {
            $this->notes[] = sprintf('%s could not be parsed: %s', $file, $this->php->lastError() ?? 'unknown error');

            return;
        }

        $this->files[] = $file;
        $this->stack[] = $file;
        $this->walkStatements($stmts, $attributes, $file);
        array_pop($this->stack);
    }

    /**
     * @param array<Node\Stmt> $stmts
     */
    private function walkStatements(array $stmts, GroupAttributes $attributes, string $file): void
    {
        foreach ($stmts as $stmt) {
            match (true) {
                $stmt instanceof Stmt\Expression => $this->handleExpression($stmt->expr, $attributes, $file),
                $stmt instanceof Stmt\Namespace_, $stmt instanceof Stmt\Declare_ => $this->walkStatements($stmt->stmts ?? [], $attributes, $file),
                $stmt instanceof Stmt\Class_ => $this->walkClass($stmt, $attributes, $file),
                $stmt instanceof Stmt\If_ => $this->walkIf($stmt, $attributes, $file),
                $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\For_, $stmt instanceof Stmt\While_, $stmt instanceof Stmt\Do_
                    => $this->walkStatements($stmt->stmts, $attributes, $file),
                $stmt instanceof Stmt\TryCatch => $this->walkTry($stmt, $attributes, $file),
                default => null,
            };
        }
    }

    private function walkClass(Stmt\Class_ $class, GroupAttributes $attributes, string $file): void
    {
        $this->properties = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $value = PhpSource::string($prop->default);
                if ($value !== null) {
                    $this->properties[$prop->name->toString()] = $value;
                }
            }
        }
        foreach ($class->getMethods() as $method) {
            $this->walkStatements($method->stmts ?? [], $attributes, $file);
        }
    }

    private function walkIf(Stmt\If_ $if, GroupAttributes $attributes, string $file): void
    {
        $this->walkStatements($if->stmts, $attributes, $file);
        foreach ($if->elseifs as $elseif) {
            $this->walkStatements($elseif->stmts, $attributes, $file);
        }
        if ($if->else !== null) {
            $this->walkStatements($if->else->stmts, $attributes, $file);
        }
    }

    private function walkTry(Stmt\TryCatch $try, GroupAttributes $attributes, string $file): void
    {
        $this->walkStatements($try->stmts, $attributes, $file);
        foreach ($try->catches as $catch) {
            $this->walkStatements($catch->stmts, $attributes, $file);
        }
    }

    private function walkCallable(Expr\Closure|Expr\ArrowFunction $callable, GroupAttributes $attributes, string $file): void
    {
        if ($callable instanceof Expr\Closure) {
            $this->walkStatements($callable->stmts, $attributes, $file);
        } else {
            $this->handleExpression($callable->expr, $attributes, $file);
        }
    }

    private function handleExpression(Expr $expr, GroupAttributes $attributes, string $file): void
    {
        if ($expr instanceof Expr\Include_) {
            $path = $this->resolvePath($expr->expr, $file);
            if ($path === null) {
                $this->notes[] = sprintf('%s:%d includes a file whose path is computed at runtime; it was not followed.', $file, $expr->getStartLine());
            } else {
                $this->walkFile($path, $attributes);
            }

            return;
        }
        if ($expr instanceof Expr\Assign) {
            $this->handleExpression($expr->expr, $attributes, $file);

            return;
        }
        if (!$expr instanceof Expr\CallLike) {
            return;
        }

        $chain = PhpSource::chain($expr);
        if ($chain !== null && $this->isRouter($chain['root'])) {
            $this->handleRouteChain($chain['calls'], $attributes, $file);

            return;
        }

        // Not a route call, but closures passed to it may register routes,
        // e.g. $this->routes(function () { ... }) in RouteServiceProvider.
        foreach ($chain['calls'] ?? [['name' => '', 'call' => $expr]] as $link) {
            if ($link['call']->isFirstClassCallable()) {
                continue;
            }
            foreach ($link['call']->getArgs() as $arg) {
                if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                    $this->walkCallable($arg->value, $attributes, $file);
                }
            }
        }
    }

    private function isRouter(Node $root): bool
    {
        if ($root instanceof Node\Name) {
            return PhpSource::shortName($root) === 'Route' || PhpSource::className($root) === 'Illuminate\\Support\\Facades\\Route';
        }

        return $root instanceof Expr\Variable && in_array($root->name, ['router', 'route'], true);
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $calls
     */
    private function handleRouteChain(array $calls, GroupAttributes $attributes, string $file): void
    {
        $terminal = null;
        foreach ($calls as $index => $link) {
            if (in_array(strtolower($link['name']), self::TERMINALS, true)) {
                $terminal = $index;
                break;
            }
        }
        if ($terminal === null) {
            return; // Route::pattern(), Route::bind(), Route::model(), ...
        }

        foreach (array_slice($calls, 0, $terminal) as $link) {
            $attributes = $this->applyAttribute($attributes, $link['name'], $link['call']);
        }
        $call = $calls[$terminal]['call'];
        $name = strtolower($calls[$terminal]['name']);
        $modifiers = array_slice($calls, $terminal + 1);

        match (true) {
            $name === 'group' => $this->handleGroup($call, $attributes, $file),
            in_array($name, ['resource', 'apiresource'], true) => $this->addResource($call, $name === 'apiresource', $attributes, $modifiers, $file),
            in_array($name, ['resources', 'apiresources'], true) => $this->addResources($call, $name === 'apiresources', $attributes, $modifiers, $file),
            in_array($name, ['singleton', 'apisingleton'], true) => $this->addSingleton($call, $name === 'apisingleton', $attributes, $modifiers, $file),
            default => $this->addRoute($name, $call, $attributes, $modifiers, $file),
        };
    }

    private function applyAttribute(GroupAttributes $attributes, string $name, Expr\CallLike $call): GroupAttributes
    {
        $first = PhpSource::arg($call, 0);

        return match (strtolower($name)) {
            'prefix' => $attributes->withPrefix(PhpSource::string($first) ?? '{dynamic}'),
            'middleware' => $attributes->withMiddleware($this->allStrings($call)),
            'withoutmiddleware' => $attributes->withoutMiddleware($this->allStrings($call)),
            'name', 'as' => $attributes->withNamePrefix(PhpSource::string($first) ?? ''),
            'namespace' => ($namespace = $this->namespaceValue($first)) !== null ? $attributes->withNamespace($namespace) : $attributes,
            'controller' => ($controller = PhpSource::string($first)) !== null ? $attributes->withController($attributes->qualify($controller)) : $attributes,
            'domain' => ($domain = PhpSource::string($first)) !== null ? $attributes->withDomain($domain) : $attributes,
            default => $attributes,
        };
    }

    private function handleGroup(Expr\CallLike $call, GroupAttributes $attributes, string $file): void
    {
        $first = PhpSource::arg($call, 0);
        $callback = $first;

        // Route::group(['prefix' => ..., 'middleware' => ...], function () { ... })
        if ($first instanceof Expr\Array_) {
            $attributes = $this->applyArrayAttributes($attributes, $first);
            $callback = PhpSource::arg($call, 1);
        }

        if ($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction) {
            $this->walkCallable($callback, $attributes, $file);

            return;
        }
        if ($callback !== null) {
            $path = $this->resolvePath($callback, $file);
            if ($path !== null) {
                $this->walkFile($path, $attributes);
            } else {
                $this->notes[] = sprintf('%s:%d loads routes from a path computed at runtime; it was not followed.', $file, $call->getStartLine());
            }
        }
    }

    private function applyArrayAttributes(GroupAttributes $attributes, Expr\Array_ $array): GroupAttributes
    {
        foreach ($array->items as $item) {
            $key = PhpSource::string($item->key);
            $attributes = match ($key) {
                'prefix' => $attributes->withPrefix(PhpSource::string($item->value) ?? '{dynamic}'),
                'middleware' => $attributes->withMiddleware(PhpSource::strings($item->value)),
                'excluded_middleware', 'withoutMiddleware' => $attributes->withoutMiddleware(PhpSource::strings($item->value)),
                'as' => $attributes->withNamePrefix(PhpSource::string($item->value) ?? ''),
                'namespace' => ($namespace = $this->namespaceValue($item->value)) !== null ? $attributes->withNamespace($namespace) : $attributes,
                'domain' => ($domain = PhpSource::string($item->value)) !== null ? $attributes->withDomain($domain) : $attributes,
                default => $attributes,
            };
        }

        return $attributes;
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $modifiers
     */
    private function addRoute(string $verb, Expr\CallLike $call, GroupAttributes $attributes, array $modifiers, string $file): void
    {
        [$methods, $uriArg, $actionArg] = match ($verb) {
            'match' => [array_map('strtoupper', PhpSource::strings(PhpSource::arg($call, 0, 'methods'))), PhpSource::arg($call, 1, 'uri'), PhpSource::arg($call, 2, 'action')],
            'view' => [['GET', 'HEAD'], PhpSource::arg($call, 0, 'uri'), null],
            'redirect', 'permanentredirect' => [['ANY'], PhpSource::arg($call, 0, 'uri'), null],
            'fallback' => [['GET', 'HEAD'], null, PhpSource::arg($call, 0, 'action')],
            default => [self::VERBS[$verb], PhpSource::arg($call, 0, 'uri'), PhpSource::arg($call, 1, 'action')],
        };

        $uri = $verb === 'fallback' ? '{fallbackPlaceholder}' : (PhpSource::string($uriArg) ?? '{dynamic}');
        $route = ['name' => null, 'middleware' => [], 'without' => []];
        foreach ($modifiers as $modifier) {
            $route = $this->applyRouteModifier($route, $modifier['name'], $modifier['call']);
        }

        if ($verb === 'view') {
            $action = ['View: ' . (PhpSource::string(PhpSource::arg($call, 1, 'view')) ?? 'dynamic'), null, null, false, []];
        } elseif ($verb === 'redirect' || $verb === 'permanentredirect') {
            $action = ['Redirect to ' . (PhpSource::string(PhpSource::arg($call, 1, 'destination')) ?? 'dynamic'), null, null, false, []];
        } else {
            $action = $this->resolveAction($actionArg, $attributes);
            if ($actionArg instanceof Expr\Array_ && $this->arrayKey($actionArg, 'uses') !== null) {
                // Legacy ['uses' => ..., 'as' => ..., 'middleware' => ...] actions.
                $route['name'] ??= PhpSource::string($this->arrayKey($actionArg, 'as'));
                array_push($route['middleware'], ...PhpSource::strings($this->arrayKey($actionArg, 'middleware')));
            }
        }

        $this->routes[] = $this->makeRoute(
            $methods === [] ? ['GET', 'HEAD'] : $methods,
            RouteUri::join($attributes->prefix, $uri),
            $route['name'] !== null ? $attributes->namePrefix . $route['name'] : null,
            $action,
            $attributes,
            $route['middleware'],
            $route['without'],
            $file,
            $call->getStartLine(),
        );
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $modifiers
     */
    private function addResource(Expr\CallLike $call, bool $api, GroupAttributes $attributes, array $modifiers, string $file): void
    {
        $name = PhpSource::string(PhpSource::arg($call, 0, 'name'));
        $controller = $this->controllerName(PhpSource::arg($call, 1, 'controller'), $attributes);
        if ($name === null || $controller === null) {
            $this->notes[] = sprintf('%s:%d defines a resource with a dynamic name or controller; it was skipped.', $file, $call->getStartLine());

            return;
        }
        $this->expandResource($name, $controller, $api ? ['index', 'store', 'show', 'update', 'destroy'] : array_keys(self::RESOURCE_ACTIONS), $attributes, $modifiers, $file, $call->getStartLine());
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $modifiers
     */
    private function addResources(Expr\CallLike $call, bool $api, GroupAttributes $attributes, array $modifiers, string $file): void
    {
        $map = PhpSource::arg($call, 0);
        if (!$map instanceof Expr\Array_) {
            return;
        }
        foreach ($map->items as $item) {
            $name = PhpSource::string($item->key);
            $controller = $this->controllerName($item->value, $attributes);
            if ($name !== null && $controller !== null) {
                $this->expandResource($name, $controller, $api ? ['index', 'store', 'show', 'update', 'destroy'] : array_keys(self::RESOURCE_ACTIONS), $attributes, $modifiers, $file, $call->getStartLine());
            }
        }
    }

    /**
     * @param list<string>                                    $actions
     * @param list<array{name: string, call: Expr\CallLike}> $modifiers
     */
    private function expandResource(string $name, string $controller, array $actions, GroupAttributes $attributes, array $modifiers, string $file, int $line): void
    {
        $names = [];
        $namePrefix = null;
        $middleware = [];
        $without = [];
        foreach ($modifiers as $modifier) {
            $values = $this->allStrings($modifier['call']);
            match (strtolower($modifier['name'])) {
                'only' => $actions = array_values(array_intersect($actions, $values)),
                'except' => $actions = array_values(array_diff($actions, $values)),
                'middleware' => array_push($middleware, ...$values),
                'withoutmiddleware' => array_push($without, ...$values),
                'names' => ($arg = PhpSource::arg($modifier['call'], 0)) instanceof Expr\Array_
                    ? $names = $this->stringMap($arg)
                    : $namePrefix = PhpSource::string($arg),
                default => null,
            };
        }

        $segments = explode('.', $name);
        $last = (string) array_pop($segments);
        $base = '';
        foreach ($segments as $segment) {
            $base = RouteUri::join($base, $segment, '{' . RouteUri::singular($segment) . '}');
        }
        $base = RouteUri::join($attributes->prefix, $base, $last);
        $parameter = '{' . RouteUri::singular($last) . '}';

        foreach ($actions as $action) {
            [$methods, $suffix] = self::RESOURCE_ACTIONS[$action];
            $routeName = $names[$action] ?? (($namePrefix ?? $name) . '.' . $action);
            $this->routes[] = $this->makeRoute(
                $methods,
                RouteUri::join($base . str_replace('{param}', $parameter, $suffix)),
                $attributes->namePrefix . $routeName,
                [$controller . '@' . $action, $controller, $action, false, []],
                $attributes,
                $middleware,
                $without,
                $file,
                $line,
            );
        }
    }

    /**
     * @param list<array{name: string, call: Expr\CallLike}> $modifiers
     */
    private function addSingleton(Expr\CallLike $call, bool $api, GroupAttributes $attributes, array $modifiers, string $file): void
    {
        $name = PhpSource::string(PhpSource::arg($call, 0, 'name'));
        $controller = $this->controllerName(PhpSource::arg($call, 1, 'controller'), $attributes);
        if ($name === null || $controller === null) {
            return;
        }
        $actions = $api ? ['show' => [['GET', 'HEAD'], ''], 'update' => [['PUT', 'PATCH'], '']]
            : ['show' => [['GET', 'HEAD'], ''], 'edit' => [['GET', 'HEAD'], '/edit'], 'update' => [['PUT', 'PATCH'], '']];
        foreach ($modifiers as $modifier) {
            $modifierName = strtolower($modifier['name']);
            if ($modifierName === 'creatable') {
                $actions += $api ? ['store' => [['POST'], '']] : ['create' => [['GET', 'HEAD'], '/create'], 'store' => [['POST'], '']];
                $actions += ['destroy' => [['DELETE'], '']];
            } elseif ($modifierName === 'destroyable') {
                $actions += ['destroy' => [['DELETE'], '']];
            }
        }
        $base = RouteUri::join($attributes->prefix, str_replace('.', '/', $name));
        foreach ($actions as $action => [$methods, $suffix]) {
            $this->routes[] = $this->makeRoute($methods, $base . $suffix, $attributes->namePrefix . $name . '.' . $action, [$controller . '@' . $action, $controller, $action, false, []], $attributes, [], [], $file, $call->getStartLine());
        }
    }

    /**
     * @param array{name: string|null, middleware: list<string>, without: list<string>} $route
     * @return array{name: string|null, middleware: list<string>, without: list<string>}
     */
    private function applyRouteModifier(array $route, string $name, Expr\CallLike $call): array
    {
        switch (strtolower($name)) {
            case 'name':
            case 'as':
                $route['name'] = PhpSource::string(PhpSource::arg($call, 0)) ?? $route['name'];
                break;
            case 'middleware':
                array_push($route['middleware'], ...$this->allStrings($call));
                break;
            case 'withoutmiddleware':
                array_push($route['without'], ...$this->allStrings($call));
                break;
            case 'can':
                $ability = PhpSource::string(PhpSource::arg($call, 0)) ?? 'ability';
                $model = PhpSource::string(PhpSource::arg($call, 1));
                $route['middleware'][] = 'can:' . $ability . ($model !== null ? ',' . $model : '');
                break;
        }

        return $route;
    }

    /**
     * @param list<string>                                                                 $methods
     * @param array{0: string, 1: string|null, 2: string|null, 3: bool, 4: list<string>} $action
     * @param list<string>                                                                 $middleware
     * @param list<string>                                                                 $without
     */
    private function makeRoute(array $methods, string $uri, ?string $name, array $action, GroupAttributes $attributes, array $middleware, array $without, string $file, int $line): RouteDefinition
    {
        $removed = [...$attributes->withoutMiddleware, ...$without];
        $effective = array_values(array_unique(array_filter(
            [...$attributes->middleware, ...$middleware],
            static fn (string $m): bool => !in_array($m, $removed, true),
        )));

        return new RouteDefinition(
            $methods,
            RouteUri::normalise($uri),
            $name,
            $action[0],
            $effective,
            $file,
            $line,
            RouteDefinition::SOURCE_STATIC,
            $action[1],
            $action[2],
            $action[3],
            $action[4],
            $action[1] !== null && $this->locator->isProjectClass($action[1]) && $this->locator->locate($action[1]) === null,
            $attributes->domain,
        );
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null, 3: bool, 4: list<string>} [label, controller, method, isClosure, closureCalls]
     */
    private function resolveAction(?Expr $action, GroupAttributes $attributes): array
    {
        if ($action instanceof Expr\Closure || $action instanceof Expr\ArrowFunction) {
            return ['Closure', null, null, true, $this->closureCalls($action)];
        }
        if ($action instanceof Expr\Array_) {
            $uses = $this->arrayKey($action, 'uses');
            if ($uses !== null) {
                return $this->resolveAction($uses, $attributes);
            }
            $class = $this->controllerName($action->items[0]->value ?? null, $attributes);
            $method = PhpSource::string($action->items[1]->value ?? null);
            if ($class !== null) {
                return [$class . '@' . ($method ?? '?'), $class, $method, false, []];
            }
        }
        if ($action instanceof Expr\ClassConstFetch) {
            $class = PhpSource::className($action);
            if ($class !== null) {
                return [$class, $class, '__invoke', false, []];
            }
        }
        $string = PhpSource::string($action);
        if ($string !== null) {
            if (str_contains($string, '@')) {
                [$class, $method] = explode('@', $string, 2);
                $class = $attributes->qualify($class);

                return [$class . '@' . $method, $class, $method, false, []];
            }
            if ($attributes->controller !== null) {
                return [$attributes->controller . '@' . $string, $attributes->controller, $string, false, []];
            }
            $class = $attributes->qualify($string);

            return [$class, $class, '__invoke', false, []];
        }

        return [$action === null ? 'Unknown' : 'Dynamic action', null, null, false, []];
    }

    private function controllerName(?Expr $expr, GroupAttributes $attributes): ?string
    {
        if ($expr === null) {
            return null;
        }
        $class = PhpSource::className($expr);
        if ($class !== null) {
            return $class;
        }
        $string = PhpSource::string($expr);

        return $string === null ? null : $attributes->qualify($string);
    }

    /**
     * @return list<string>
     */
    private function closureCalls(Expr\Closure|Expr\ArrowFunction $closure): array
    {
        $calls = [];
        $nodes = (new NodeFinder())->find($closure instanceof Expr\Closure ? $closure->stmts : [$closure->expr], static fn (Node $n): bool => $n instanceof Expr\FuncCall
            || $n instanceof Expr\StaticCall
            || $n instanceof Expr\Eval_
            || $n instanceof Expr\ShellExec);
        foreach ($nodes as $node) {
            $name = match (true) {
                $node instanceof Expr\Eval_ => 'eval',
                $node instanceof Expr\ShellExec => 'shell_exec',
                default => PhpSource::callName($node),
            };
            if ($name !== null) {
                $calls[] = $name;
            }
            if (count($calls) >= self::MAX_CLOSURE_CALLS) {
                break;
            }
        }

        return array_values(array_unique($calls));
    }

    /**
     * Project-relative path of a route file reference such as
     * base_path('routes/api.php') or __DIR__.'/../routes/web.php'.
     */
    private function resolvePath(Expr $expr, string $currentFile): ?string
    {
        $relative = match (true) {
            $expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toString() === 'base_path'
                => PhpSource::string(PhpSource::arg($expr, 0)),
            $expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toString() === 'app_path'
                => ($p = PhpSource::string(PhpSource::arg($expr, 0))) !== null ? 'app/' . ltrim($p, '/') : null,
            $expr instanceof Expr\BinaryOp\Concat && $expr->left instanceof Scalar\MagicConst\Dir
                => ($p = PhpSource::string($expr->right)) !== null ? dirname($currentFile) . '/' . ltrim($p, '/') : null,
            $expr instanceof Scalar\String_ => $this->literalPath($expr->value),
            default => null,
        };

        return $relative === null ? null : self::normalisePath($relative);
    }

    private function literalPath(string $path): ?string
    {
        $root = str_replace('\\', '/', $this->context->root());
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return str_starts_with($path, '/') ? null : $path;
    }

    private static function normalisePath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private function pathList(?Expr $expr, string $file): array
    {
        if ($expr === null) {
            return [];
        }
        $items = $expr instanceof Expr\Array_ ? array_map(static fn (Node\ArrayItem $i): Expr => $i->value, $expr->items) : [$expr];
        $paths = [];
        foreach ($items as $item) {
            $path = $this->resolvePath($item, $file);
            if ($path !== null) {
                $paths[] = $path;
            } else {
                $this->notes[] = sprintf('%s:%d registers a route file whose path is computed at runtime; it was not followed.', $file, $item->getStartLine());
            }
        }

        return $paths;
    }

    private function namespaceValue(?Expr $expr): ?string
    {
        if ($expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
            return $this->properties[$expr->name->toString()] ?? null;
        }

        return PhpSource::string($expr);
    }

    /**
     * All string arguments of a call: middleware('a', 'b') and middleware(['a', 'b']).
     *
     * @return list<string>
     */
    private function allStrings(Expr\CallLike $call): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }
        $values = [];
        foreach ($call->getArgs() as $arg) {
            array_push($values, ...PhpSource::strings($arg->value));
        }

        return $values;
    }

    private function arrayKey(Expr\Array_ $array, string $key): ?Expr
    {
        foreach ($array->items as $item) {
            if (PhpSource::string($item->key) === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(Expr\Array_ $array): array
    {
        $map = [];
        foreach ($array->items as $item) {
            $key = PhpSource::string($item->key);
            $value = PhpSource::string($item->value);
            if ($key !== null && $value !== null) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    private function withControllerMiddleware(RouteDefinition $route): RouteDefinition
    {
        if ($route->controller === null) {
            return $route;
        }
        $extra = $this->controllers->forMethod($route->controller, $route->controllerMethod);
        if ($extra === []) {
            return $route;
        }

        return new RouteDefinition(
            $route->methods,
            $route->uri,
            $route->name,
            $route->action,
            array_values(array_unique([...$route->middleware, ...$extra])),
            $route->file,
            $route->line,
            $route->source,
            $route->controller,
            $route->controllerMethod,
            $route->isClosure,
            $route->closureCalls,
            $route->controllerMissing,
            $route->domain,
        );
    }
}
