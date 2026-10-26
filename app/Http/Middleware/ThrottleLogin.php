<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Support\RateLimiter;
use Closure;

final class ThrottleLogin implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $keys = ['login:ip:' . $request->ip()];

        if ($email !== '') {
            $keys[] = 'login:email:' . $email;
        }

        $retryAfter = $this->limiter->attempt($keys, 5, 60);

        if ($retryAfter !== null) {
            throw new HttpException(
                429,
                'Too many sign-in attempts. Try again shortly.',
                ['Retry-After' => (string) $retryAfter],
                'rate_limited',
            );
        }

        return $next($request);
    }
}
