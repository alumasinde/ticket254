<?php

declare(strict_types=1);

$connection = [
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_NAME', 'hudumadesk_dev'),
    'username' => (string) env('DB_USER', ''),
    'password' => (string) env('DB_PASS', ''),
    'charset' => 'utf8mb4',
];

return [
    'default' => (string) env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => $connection,
    ],
];
