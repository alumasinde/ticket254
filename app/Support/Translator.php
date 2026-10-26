<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionInterface;

final class Translator
{
    /** @var array<string, string>|null */
    private ?array $lines = null;

    private ?string $loadedLocale = null;

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function locale(): string
    {
        $request = $this->request();
        $requested = $request?->input('lang');

        if (is_string($requested) && in_array($requested, ['en', 'sw'], true)) {
            $this->session->put('locale', $requested);

            return $requested;
        }

        $stored = $this->session->get('locale');

        if (is_string($stored) && in_array($stored, ['en', 'sw'], true)) {
            return $stored;
        }

        $default = (string) config('app.locale', 'en');

        return in_array($default, ['en', 'sw'], true) ? $default : 'en';
    }

    /**
     * @param array<string, string|int> $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $lines = $this->lines();

        $line = $lines[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private function lines(): array
    {
        $locale = $this->locale();

        if ($this->lines !== null && $this->loadedLocale === $locale) {
            return $this->lines;
        }

        $this->loadedLocale = $locale;
        $this->lines = $this->load($locale);

        if ($this->lines === [] && $locale !== 'en') {
            $this->lines = $this->load('en');
        }

        return $this->lines;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        $path = base_path('resources/lang/' . $locale . '.php');

        if (!is_file($path)) {
            return [];
        }

        $lines = require $path;

        return is_array($lines) ? $lines : [];
    }

    private function request(): ?Request
    {
        $application = Application::instance();

        if ($application === null) {
            return null;
        }

        $container = $application->container();

        return $container->hasInstance(Request::class) ? $container->get(Request::class) : null;
    }
}
