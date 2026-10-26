<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ControllerDispatcher;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Gate;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Container;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouterTest extends TestCase
{
    public function test_matches_route_parameters_and_injects_them(): void
    {
        $router = $this->makeRouter();

        $router->get('/tickets/{ref}', function (Request $request, string $ref): Response {
            return Response::html($ref . '|' . $request->path());
        });

        $response = $router->dispatch(Request::create('GET', '/tickets/HD-000123'));

        $this->assertSame('HD-000123|/tickets/HD-000123', $response->content());
    }

    public function test_cast_route_parameters_to_int_when_requested(): void
    {
        $router = $this->makeRouter();

        $router->get('/users/{id}', function (int $id): Response {
            return Response::html((string) $id);
        });

        $response = $router->dispatch(Request::create('GET', '/users/07'));

        $this->assertSame('7', $response->content());
    }

    public function test_group_prefix_middleware_and_named_routes(): void
    {
        $router = $this->makeRouter();

        $router->group(['prefix' => '/app', 'middleware' => [FirstMiddleware::class]], function (Router $router): void {
            $router->get('/tickets/{ref}', function (): Response {
                return Response::html('ok');
            })->name('tickets.show')->middleware(SecondMiddleware::class);
        });

        $this->assertSame('/app/tickets/HD%20001', $router->route('tickets.show', ['ref' => 'HD 001']));

        $response = $router->dispatch(Request::create('GET', '/app/tickets/HD-1'));
        $this->assertSame('ABok', $response->content());
    }

    public function test_permission_denied_throws_forbidden(): void
    {
        $router = $this->makeRouter(allowed: false);
        $router->get('/secret', function (): Response {
            return Response::html('secret');
        })->can('settings.manage');

        $this->expectException(ForbiddenException::class);

        $router->dispatch(Request::create('GET', '/secret'));
    }

    public function test_permission_granted_runs_the_route(): void
    {
        $router = $this->makeRouter(allowed: true);
        $router->get('/secret', function (): Response {
            return Response::html('secret');
        })->can('settings.manage');

        $this->assertSame('secret', $router->dispatch(Request::create('GET', '/secret'))->content());
    }

    public function test_unknown_path_throws_not_found(): void
    {
        $router = $this->makeRouter();

        $this->expectException(NotFoundException::class);

        $router->dispatch(Request::create('GET', '/missing'));
    }

    public function test_wrong_method_throws_method_not_allowed_with_allow_header(): void
    {
        $router = $this->makeRouter();
        $router->get('/tickets', function (): Response {
            return Response::html('list');
        });

        try {
            $router->dispatch(Request::create('POST', '/tickets'));
            $this->fail('Expected a MethodNotAllowedException.');
        } catch (MethodNotAllowedException $exception) {
            $this->assertSame(405, $exception->status);
            $this->assertStringContainsString('GET', $exception->headers['Allow']);
            $this->assertStringContainsString('HEAD', $exception->headers['Allow']);
        }
    }

    public function test_head_requests_fall_back_to_get_routes(): void
    {
        $router = $this->makeRouter();
        $router->get('/ping', function (): Response {
            return Response::html('pong');
        });

        $this->assertSame('pong', $router->dispatch(Request::create('HEAD', '/ping'))->content());
    }

    public function test_duplicate_route_names_are_rejected(): void
    {
        $router = $this->makeRouter();
        $router->get('/one', fn (): Response => Response::html('one'))->name('page');
        $router->get('/two', fn (): Response => Response::html('two'));

        $this->expectException(RuntimeException::class);

        $router->routes()[1]->name('page');
    }

    public function test_missing_named_route_fails(): void
    {
        $router = $this->makeRouter();

        $this->expectException(RuntimeException::class);

        $router->route('missing.route');
    }

    private function makeRouter(bool $allowed = true): Router
    {
        $container = new Container();
        $container->instance(Gate::class, new FixedGate($allowed));

        return new Router($container, new ControllerDispatcher($container));
    }
}

final class FixedGate implements Gate
{
    public function __construct(private readonly bool $allowed)
    {
    }

    public function allows(Request $request, string $permission): bool
    {
        return $this->allowed;
    }
}

final class FirstMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return Response::html('A' . $response->content());
    }
}

final class SecondMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return Response::html('B' . $response->content());
    }
}
