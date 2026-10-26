<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionAuth;
use Closure;

final class RequireGuest implements Middleware
{
    public function __construct(private readonly SessionAuth $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            return redirect(route('app.home'));
        }

        return $next($request);
    }
}
