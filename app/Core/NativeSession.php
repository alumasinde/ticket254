<?php

declare(strict_types=1);

namespace App\Core;

final class NativeSession implements SessionInterface
{
    private bool $started = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function beginRequest(): void
    {
        $this->start();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            $this->ageFlash();

            return;
        }

        $savePath = $this->config['save_path'] ?? null;

        if (is_string($savePath) && $savePath !== '') {
            if (!is_dir($savePath)) {
                mkdir($savePath, 0775, true);
            }

            session_save_path($savePath);
        }

        session_name((string) ($this->config['name'] ?? 'hd_session'));
        session_set_cookie_params([
            'lifetime' => ((int) ($this->config['lifetime'] ?? 120)) * 60,
            'path' => (string) ($this->config['path'] ?? '/'),
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($this->config['same_site'] ?? 'Lax'),
        ]);

        session_start();
        $this->started = true;
        $this->ageFlash();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $_SESSION);
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
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
        $_SESSION[$key] = $value;

        if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
            $_SESSION['_flash'] = ['old' => [], 'new' => []];
        }

        if (!in_array($key, $_SESSION['_flash']['new'], true)) {
            $_SESSION['_flash']['new'][] = $key;
        }
    }

    public function all(): array
    {
        $this->start();

        return $_SESSION;
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->started = false;
    }

    private function ageFlash(): void
    {
        $flash = $_SESSION['_flash'] ?? [];
        $old = $flash['old'] ?? [];
        $new = $flash['new'] ?? [];

        foreach ($old as $key) {
            unset($_SESSION[$key]);
        }

        $_SESSION['_flash'] = [
            'old' => $new,
            'new' => [],
        ];
    }
}
