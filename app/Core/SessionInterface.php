<?php

declare(strict_types=1);

namespace App\Core;

interface SessionInterface
{
    public function start(): void;

    public function beginRequest(): void;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function pull(string $key, mixed $default = null): mixed;

    public function flash(string $key, mixed $value): void;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;

    public function regenerate(): void;

    public function destroy(): void;
}
