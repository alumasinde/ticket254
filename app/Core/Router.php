<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;
use Closure;
use RuntimeException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /** @var list<array{prefix?: string, middleware?: string|list<string>}> */
    private array $groupStack = [];

    /** @var array<int, string> */
    private array $patternCache = [];

    /**
     * @param array<string, class-string> $middlewareAliases
     */
    public function __construct(
        private readonly Container $container,
        private readonly ControllerDispatcher $dispatcher,
        private readonly array $middlewareAliases = [],
    ) {
    }

    /**
     * @param array{prefix?: string, middleware?: string|list<string>} $attributes
     */
    public function group(array $attributes, Closure $routes): void
    {
        $this->groupStack[] = $attributes;

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * @param string|list<string> $middleware
     */
    public function get(string $uri, mixed $handler, string|array $middleware = []): Route
    {
        return $this->addRoute(['GET'], $uri, $handler, $middleware);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function post(string $uri, mixed $handler, string|array $middleware = []): Route
    {
        return $this->addRoute(['POST'], $uri, $handler, $middleware);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function put(string $uri, mixed $handler, string|array $middleware = []): Route
    {
        return $this->addRoute(['PUT'], $uri, $handler, $middleware);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function patch(string $uri, mixed $handler, string|array $middleware = []): Route
    {
        return $this->addRoute(['PATCH'], $uri, $handler, $middleware);
    }

    /**
     * @param string|list<string> $middleware
     */
    public function delete(string $uri, mixed $handler, string|array $middleware = []): Route
    {
        return $this->addRoute(['DELETE'], $uri, $handler, $middleware);
    }

    /**
     * @param list<string> $methods
     * @param string|list<string> $middleware
     */
    public function addRoute(array $methods, string $uri, mixed $handler, string|array $middleware = []): Route
    {
        $route = new Route(
            $methods,
            $this->applyPrefix($uri),
            $handler,
            function (string $name, Route $route): void {
                if (isset($this->namedRoutes[$name])) {
                    throw new RuntimeException("Route name [$name] is already registered.");
                }

                $this->namedRoutes[$name] = $route;
            },
        );

        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            foreach ((array) ($group['middleware'] ?? []) as $item) {
                $groupMiddleware[] = $item;
            }
        }

        $route->middleware($groupMiddleware);

        foreach ((array) $middleware as $item) {
            $route->middleware($item);
        }

        $this->routes[] = $route;

        return $route;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route, $path);

            if ($params === null) {
                continue;
            }

            if (!in_array($lookupMethod, $route->methods(), true)) {
                foreach ($route->methods() as $routeMethod) {
                    $allowed[$routeMethod] = true;
                }

                continue;
            }

            $request->setRouteParams($params);

            return $this->runPipeline($route, $request);
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }

            $methods = array_keys($allowed);
            sort($methods);

            throw new MethodNotAllowedException($methods);
        }

        throw new NotFoundException();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        $route = $this->namedRoutes[$name] ?? null;

        if ($route === null) {
            throw new RuntimeException("Route [$name] is not defined.");
        }

        $used = [];

        $uri = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $match) use ($parameters, &$used): string {
                $key = $match[1];

                if (!array_key_exists($key, $parameters)) {
                    throw new RuntimeException("Missing route parameter [$key].");
                }

                $used[$key] = true;

                return rawurlencode((string) $parameters[$key]);
            },
            $route->uri(),
        );

        if (!is_string($uri)) {
            throw new RuntimeException("Unable to build URL for route [$name].");
        }

        $extra = array_diff_key($parameters, $used);

        if ($extra !== []) {
            $uri .= '?' . http_build_query($extra);
        }

        return $uri;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function runPipeline(Route $route, Request $request): Response
    {
        $runner = function (Request $request) use ($route): Response {
            $permission = $route->permission();

            if ($permission !== null && !$this->container->get(Gate::class)->allows($request, $permission)) {
                throw new ForbiddenException();
            }

            $result = $this->dispatcher->dispatch($route, $request);

            if ($result instanceof Response) {
                return $result;
            }

            if (is_string($result)) {
                return Response::html($result);
            }

            throw new RuntimeException('Route handlers must return a Response or a string.');
        };

        foreach (array_reverse($route->middlewareList()) as $middleware) {
            $instance = $this->resolveMiddleware($middleware);
            $next = $runner;
            $runner = static fn (Request $request): Response => $instance->handle($request, $next);
        }

        return $runner($request);
    }

    private function resolveMiddleware(string $middleware): object
    {
        $class = $this->middlewareAliases[$middleware] ?? $middleware;

        if (!class_exists($class)) {
            throw new RuntimeException("Middleware [$middleware] is not defined.");
        }

        $instance = $this->container->get($class);

        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException("Middleware [$middleware] must define a handle method.");
        }

        return $instance;
    }

    /**
     * @return array<string, string>|null
     */
    private function match(Route $route, string $path): ?array
    {
        $pattern = $this->patternCache[spl_object_id($route)] ??= $this->compile($route->uri());

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function compile(string $uri): string
    {
        $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$uri];
        $pattern = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $match) === 1) {
                $pattern .= '(?P<' . $match[1] . '>[^/]+)';
                continue;
            }

            $pattern .= preg_quote($part, '#');
        }

        return '#^' . $pattern . '$#';
    }

    private function applyPrefix(string $uri): string
    {
        $prefix = '';

        foreach ($this->groupStack as $group) {
            $part = trim((string) ($group['prefix'] ?? ''), '/');

            if ($part !== '') {
                $prefix .= '/' . $part;
            }
        }

        $combined = preg_replace('#/+#', '/', $prefix . '/' . $uri) ?? '/';
        $full = '/' . trim($combined, '/');

        return $full === '/' ? '/' : rtrim($full, '/');
    }
}
