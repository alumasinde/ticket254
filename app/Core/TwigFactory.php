<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

final class TwigFactory
{
    private readonly Environment $environment;

    private readonly FilesystemLoader $loader;

    public function __construct(string $viewsPath, string $cachePath, bool $debug)
    {
        $this->loader = new FilesystemLoader($viewsPath);
        $this->environment = new Environment($this->loader, [
            'cache' => $debug ? false : $cachePath,
            'debug' => $debug,
            'strict_variables' => $debug,
            'autoescape' => 'html',
            'charset' => 'UTF-8',
        ]);

        $this->registerFunctions();
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->environment->render($template, $data);
    }

    public function exists(string $template): bool
    {
        return $this->loader->exists($template);
    }

    private function registerFunctions(): void
    {
        $this->environment->addFunction(new TwigFunction(
            'route',
            static fn (string $name, array $parameters = []): string => route($name, $parameters),
        ));

        $this->environment->addFunction(new TwigFunction(
            'asset',
            static fn (string $path): string => asset($path),
        ));

        $this->environment->addFunction(new TwigFunction(
            'config',
            static fn (string $key, mixed $default = null): mixed => config($key, $default),
        ));

        $this->environment->addFunction(new TwigFunction(
            'old',
            static fn (string $key, mixed $default = null): mixed => old($key, $default),
        ));

        $this->environment->addFunction(new TwigFunction(
            'flash',
            static fn (string $key, mixed $default = null): mixed => flash($key, $default),
        ));

        $this->environment->addFunction(new TwigFunction(
            'can',
            static fn (string $permission): bool => can($permission),
        ));

        $this->environment->addFunction(new TwigFunction(
            'csrf_token',
            static fn (): string => csrf_token(),
        ));

        $this->environment->addFunction(new TwigFunction(
            'csrf_field',
            static fn (): Markup => new Markup(csrf_field(), 'UTF-8'),
            ['is_safe' => ['html']],
        ));
    }
}
