<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionAuth;
use Closure;

final class Authenticate implements Middleware
{
    public function __construct(private readonly SessionAuth $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->auth->check()) {
            return $this->reject($request);
        }

        if ($this->auth->hasExpired()) {
            $this->auth->destroy();

            return $this->reject($request, 'Your session expired. Please sign in again.');
        }

        $this->auth->touch();

        return $next($request);
    }

    private function reject(Request $request, string $message = 'Please sign in to continue.'): Response
    {
        if ($request->expectsJson()) {
            throw new HttpException(401, $message, [], 'unauthenticated');
        }

        return redirect(route('login'));
    }
}
