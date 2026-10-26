<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Database\Database;
use App\Policies\PermissionGate;
use App\Services\Tenants\TenantContext;
use Dotenv\Dotenv;
use ErrorException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Throwable;

final class Application
{
    private static ?self $instance = null;

    private readonly string $basePath;

    private readonly Container $container;

    private bool $booted = false;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
        $this->container = new Container();
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        self::$instance = $this;

        $this->ensureStorage();
        $this->loadEnvironment();

        $config = new Config();
        $config->loadDirectory($this->basePath . '/config');

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        $this->registerBindings($config);
        $this->registerErrorHandling();
        $this->loadRoutes();

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        $this->container->forget(TenantContext::class);
        $this->container->instance(Request::class, $request);
        $this->container->instance('request', $request);

        try {
            $this->container->get(SessionInterface::class)->beginRequest();

            return $this->container->get(Router::class)->dispatch($request);
        } catch (ValidationException $exception) {
            return $this->handleValidationException($request, $exception);
        } catch (HttpException $exception) {
            return $this->handleHttpException($request, $exception);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedException($request, $exception);
        }
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    private function ensureStorage(): void
    {
        foreach (['logs', 'cache/twig', 'framework/sessions', 'private'] as $directory) {
            $path = $this->basePath . '/storage/' . $directory;

            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    private function loadEnvironment(): void
    {
        if (is_file($this->basePath . '/.env')) {
            Dotenv::createImmutable($this->basePath)->safeLoad();
        }
    }

    private function registerBindings(Config $config): void
    {
        $container = $this->container;

        $container->instance('app', $this);
        $container->instance(self::class, $this);
        $container->instance(Container::class, $container);
        $container->instance('config', $config);
        $container->instance(Config::class, $config);

        $container->bind(LoggerInterface::class, static function () use ($config): Logger {
            $logger = new Logger('app');
            $handler = new StreamHandler(
                (string) $config->get('app.log.path'),
                Logger::toMonologLevel((string) $config->get('app.log.level', 'warning')),
            );
            $handler->setFormatter(new LineFormatter(null, null, true, true));
            $logger->pushHandler($handler);

            return $logger;
        });
        $container->bind('logger', static fn (Container $c): object => $c->get(LoggerInterface::class));

        $container->bind(Database::class, static fn (): Database => new Database(
            $config->get('database.connections.mysql'),
        ));
        $container->bind('db', static fn (Container $c): object => $c->get(Database::class));

        $session = new NativeSession($config->get('security.session', []));
        $container->instance(SessionInterface::class, $session);
        $container->instance('session', $session);

        $container->instance(Gate::class, new PermissionGate($container));
        $container->instance('gate', $container->get(Gate::class));

        $container->instance(Validator::class, new Validator());

        $container->instance(TwigFactory::class, new TwigFactory(
            $this->basePath . '/resources/views',
            $this->basePath . '/storage/cache/twig',
            (bool) $config->get('app.debug', false),
        ));
        $container->instance('view', $container->get(TwigFactory::class));

        $container->instance(ControllerDispatcher::class, new ControllerDispatcher($container));

        $container->instance(Router::class, new Router(
            $container,
            $container->get(ControllerDispatcher::class),
            (array) $config->get('app.middleware_aliases', []),
        ));
        $container->instance('router', $container->get(Router::class));
    }

    private function loadRoutes(): void
    {
        $router = $this->container->get(Router::class);

        foreach (['web.php', 'api.php', 'public.php', 'platform.php'] as $file) {
            $path = $this->basePath . '/routes/' . $file;

            if (!is_file($path)) {
                continue;
            }

            (static function (Router $router) use ($path): void {
                require $path;
            })($router);
        }
    }

    private function handleValidationException(Request $request, ValidationException $exception): Response
    {
        $message = 'Please correct the highlighted fields.';

        if ($request->expectsJson()) {
            return $this->jsonError(422, $message, [], $exception->errors(), 'validation_failed');
        }

        return (new RedirectResponse($this->backPath($request), 302, $this->container->get(SessionInterface::class)))
            ->withErrors($exception->errors())
            ->withInput($request->all());
    }

    private function handleHttpException(Request $request, HttpException $exception): Response
    {
        $status = $exception->status;
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : match ($status) {
            404 => 'Not found.',
            403 => 'Forbidden.',
            default => 'Request failed.',
        };

        if ($status >= 500) {
            $this->container->get(LoggerInterface::class)->error('HTTP error', [
                'status' => $status,
                'message' => $message,
            ]);
        }

        if ($request->expectsJson()) {
            return $this->jsonError($status, $message, $exception->headers, null, $exception->errorCode, $exception->context);
        }

        return $this->renderErrorPage($status, $message, $exception->headers);
    }

    private function handleUnexpectedException(Request $request, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(8));

        $this->container->get(LoggerInterface::class)->error('Unhandled exception', [
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        $headers = ['X-Error-Id' => $errorId];

        if ($request->expectsJson()) {
            return $this->jsonError(500, 'Something went wrong.', $headers, null, 'server_error');
        }

        $debug = (bool) $this->container->get(Config::class)->get('app.debug', false);
        $trace = null;
        $message = '';

        if ($debug) {
            $message = $exception->getMessage();
            $trace = sprintf(
                "%s: %s\n%s:%d\n\n%s",
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString(),
            );
        }

        return $this->renderErrorPage(500, $message, $headers, ['trace' => $trace]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    private function renderErrorPage(int $status, string $message, array $headers = [], array $extra = []): Response
    {
        $twig = $this->container->get(TwigFactory::class);
        $template = 'errors/' . $status . '.twig';

        if (!$twig->exists($template)) {
            $template = $twig->exists('errors/error.twig') ? 'errors/error.twig' : 'errors/500.twig';
        }

        $data = array_merge([
            'status' => $status,
            'message' => $message,
            'debug' => (bool) $this->container->get(Config::class)->get('app.debug', false),
            'trace' => null,
        ], $extra);

        try {
            $content = $twig->render($template, $data);
        } catch (Throwable $exception) {
            $this->container->get(LoggerInterface::class)->error('Error page failed to render', [
                'exception' => $exception,
            ]);
            $content = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
                . '<body><h1>Something went wrong</h1></body></html>';
        }

        return Response::html($content, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string>|null $fields
     * @param array<string, mixed> $context
     */
    private function jsonError(
        int $status,
        string $message,
        array $headers = [],
        ?array $fields = null,
        ?string $code = null,
        array $context = [],
    ): Response {
        $error = array_merge([
            'code' => $code ?? $this->errorCode($status),
            'message' => $message,
        ], $context);

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return Response::json(['error' => $error], $status, $headers);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'file_too_large',
            415 => 'file_type_not_allowed',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => 'server_error',
        };
    }

    private function backPath(Request $request): string
    {
        $referer = $request->header('Referer');
        $path = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;

        if (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
            return $path;
        }

        return '/';
    }

    private function registerErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception): void {
            try {
                $this->container->get(LoggerInterface::class)->critical('Uncaught exception', [
                    'exception' => $exception,
                ]);
            } catch (Throwable) {
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }

            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
                . '<body><h1>Something went wrong</h1></body></html>';
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            try {
                $this->container->get(LoggerInterface::class)->critical('Fatal error', $error);
            } catch (Throwable) {
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head>'
                    . '<body><h1>Something went wrong</h1></body></html>';
            }
        });
    }
}
