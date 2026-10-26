<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
        array $headers = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
    ) {
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $headers = self::headersFromServer($server);
        $body = $_POST;
        $contentType = $headers['content-type'] ?? '';

        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $method = self::applyMethodOverride($method, $body);

        return new self(
            $method,
            self::normalizePath((string) ($server['REQUEST_URI'] ?? '/')),
            $_GET,
            $body,
            $_FILES,
            $headers,
            $_COOKIE,
            $server,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public static function create(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
    ): self {
        $path = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];

        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $method = strtoupper($method);
        $method = self::applyMethodOverride($method, $data);

        return new self(
            $method,
            self::normalizePath(is_string($path) && $path !== '' ? $path : '/'),
            $query,
            $method === 'GET' ? [] : $data,
            $files,
            $headers,
            $cookies,
            [],
        );
    }

    public static function normalizePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $segments = array_map(
            static fn (string $segment): string => rawurldecode($segment),
            explode('/', trim($path, '/')),
        );

        $normalized = '/' . implode('/', $segments);

        return $normalized === '/' || $normalized === '' ? '/' : rtrim($normalized, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '')), 'application/json');
    }

    public function expectsJson(): bool
    {
        return str_contains(strtolower($this->header('Accept', '')), 'application/json')
            || strtolower($this->header('X-Requested-With', '')) === 'xmlhttprequest'
            || $this->isJson()
            || str_starts_with($this->path, '/api/');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->body);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = array_key_exists($key, $this->body) ? $this->body[$key] : ($this->query[$key] ?? null);

        return $value ?? $default;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->body) || array_key_exists($key, $this->query)) {
                $result[$key] = $this->input($key);
            }
        }

        return $result;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[strtolower($name)] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($server[$key])) {
                $headers[strtolower($name)] = (string) $server[$key];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function applyMethodOverride(string $method, array $body): string
    {
        if ($method !== 'POST' || !isset($body['_method'])) {
            return $method;
        }

        $override = strtoupper((string) $body['_method']);

        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $method;
    }
}
