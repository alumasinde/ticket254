<?php

declare(strict_types=1);

return [
    'name' => (string) env('APP_NAME', 'HudumaDesk'),
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'timezone' => 'UTC',
    'locale' => 'en',

    'middleware_aliases' => [],

    'log' => [
        'path' => BASE_PATH . '/storage/logs/app.log',
        'level' => (string) env('LOG_LEVEL', 'warning'),
    ],
];
