<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

interface Middleware
{
    public function handle(Request $request, Closure $next): Response;
}
