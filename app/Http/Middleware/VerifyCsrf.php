<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionInterface;
use Closure;

final class VerifyCsrf implements Middleware
{
    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $expected = $this->session->get('_token');
        $provided = $request->header('X-CSRF-Token') ?? $request->input('_token');

        if (!is_string($expected)
            || $expected === ''
            || !is_string($provided)
            || !hash_equals($expected, $provided)
        ) {
            throw new HttpException(
                403,
                'Your session token expired. Refresh the page and try again.',
                [],
                'csrf_failed',
            );
        }

        return $next($request);
    }
}
