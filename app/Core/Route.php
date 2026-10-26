<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    private ?string $permission = null;

    /**
     * @param list<string> $methods
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly mixed $handler,
        private readonly Closure $nameRegistrar,
    ) {
    }

    public function name(string $name): self
    {
        if ($this->name !== null && $this->name !== $name) {
            throw new \RuntimeException("Route [{$this->uri}] is already named [{$this->name}].");
        }

        $this->name = $name;
        ($this->nameRegistrar)($name, $this);

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function middleware(string|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    public function can(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return list<string>
     */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function nameValue(): ?string
    {
        return $this->name;
    }

    public function permission(): ?string
    {
        return $this->permission;
    }
}
