<?php

declare(strict_types=1);

return [
    'name' => (string) env('APP_NAME', 'HudumaDesk'),
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'timezone' => 'UTC',
    'locale' => 'en',

    'middleware_aliases' => [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'guest' => \App\Http\Middleware\RequireGuest::class,
        'tenant' => \App\Http\Middleware\ResolveTenant::class,
        'csrf' => \App\Http\Middleware\VerifyCsrf::class,
        'throttle.login' => \App\Http\Middleware\ThrottleLogin::class,
    ],

    'log' => [
        'path' => BASE_PATH . '/storage/logs/app.log',
        'level' => (string) env('LOG_LEVEL', 'warning'),
    ],
];
