<?php

declare(strict_types=1);

namespace App\Core;

final class ArraySession implements SessionInterface
{
    private bool $started = false;

    public int $regenerations = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function start(): void
    {
        $this->ensureFlash();
        $this->started = true;
    }

    public function beginRequest(): void
    {
        $this->ensureFlash();

        $old = $this->data['_flash']['old'];
        $new = $this->data['_flash']['new'];

        foreach ($old as $key) {
            unset($this->data[$key]);
        }

        $this->data['_flash'] = [
            'old' => $new,
            'new' => [],
        ];

        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->start();
        $this->data[$key] = $value;

        if (!in_array($key, $this->data['_flash']['new'], true)) {
            $this->data['_flash']['new'][] = $key;
        }
    }

    public function all(): array
    {
        $this->start();

        return $this->data;
    }

    public function regenerate(): void
    {
        $this->start();
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->start();
        $this->data = [
            '_flash' => [
                'old' => [],
                'new' => [],
            ],
        ];
    }

    private function ensureFlash(): void
    {
        if (!isset($this->data['_flash']) || !is_array($this->data['_flash'])) {
            $this->data['_flash'] = ['old' => [], 'new' => []];

            return;
        }

        if (!isset($this->data['_flash']['old']) || !is_array($this->data['_flash']['old'])) {
            $this->data['_flash']['old'] = [];
        }

        if (!isset($this->data['_flash']['new']) || !is_array($this->data['_flash']['new'])) {
            $this->data['_flash']['new'] = [];
        }
    }
}
