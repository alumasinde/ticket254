<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Exceptions\HttpException;
use App\Core\RedirectResponse;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionInterface;

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}

function app(?string $abstract = null): mixed
{
    $application = Application::instance();

    if ($application === null) {
        throw new RuntimeException('The application has not booted yet.');
    }

    return $abstract === null ? $application : $application->container()->get($abstract);
}

function config(string $key, mixed $default = null): mixed
{
    return app('config')->get($key, $default);
}

function base_path(string $path = ''): string
{
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function route(string $name, array $parameters = []): string
{
    return app('router')->route($name, $parameters);
}

function asset(string $path): string
{
    $base = rtrim((string) config('app.url', ''), '/');

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

/**
 * @param array<string, mixed> $data
 */
function view(string $template, array $data = []): Response
{
    $twig = app('view');

    return Response::html($twig->render($template, $data));
}

function redirect(string $location, int $status = 302): RedirectResponse
{
    return new RedirectResponse($location, $status, app(SessionInterface::class));
}

function back(string $fallback = '/'): RedirectResponse
{
    $referer = app(Request::class)->header('Referer', '');
    $path = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;

    if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $path = $fallback;
    }

    return redirect($path);
}

function session(?string $key = null, mixed $value = null): mixed
{
    /** @var SessionInterface $session */
    $session = app(SessionInterface::class);

    if ($key === null) {
        return $session;
    }

    if (func_num_args() === 2) {
        $session->put($key, $value);

        return null;
    }

    return $session->get($key);
}

function old(string $key, mixed $default = null): mixed
{
    $input = app(SessionInterface::class)->get('_old_input', []);

    return is_array($input) && array_key_exists($key, $input) ? $input[$key] : $default;
}

function flash(string $key, mixed $default = null): mixed
{
    return app(SessionInterface::class)->pull($key, $default);
}

function can(string $permission): bool
{
    $container = app()->container();

    if (!$container->hasInstance(Request::class)) {
        return false;
    }

    return $container->get(App\Core\Gate::class)->allows($container->get(Request::class), $permission);
}

/**
 * @param array<string, string|int> $replace
 */
function t(string $key, array $replace = []): string
{
    return app(App\Support\Translator::class)->get($key, $replace);
}

function csrf_token(): string
{
    $session = app(SessionInterface::class);
    $token = $session->get('_token');

    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $session->put('_token', $token);
    }

    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function abort(int $status, string $message = ''): never
{
    $message = $message !== '' ? $message : match ($status) {
        400 => 'Bad request.',
        401 => 'Unauthenticated.',
        403 => 'Forbidden.',
        404 => 'Not found.',
        405 => 'Method not allowed.',
        409 => 'Conflict.',
        413 => 'File too large.',
        415 => 'File type not allowed.',
        422 => 'The given data was invalid.',
        429 => 'Too many requests.',
        default => 'Something went wrong.',
    };

    throw new HttpException($status, $message);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
