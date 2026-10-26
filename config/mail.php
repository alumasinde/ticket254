<?php

declare(strict_types=1);

return [
    'driver' => (string) env('MAIL_DRIVER', 'log'),
    'host' => (string) env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', 587),
    'username' => (string) env('MAIL_USER', ''),
    'password' => (string) env('MAIL_PASS', ''),
    'from_address' => (string) env('MAIL_FROM_ADDRESS', 'noreply@hudumadesk.co.ke'),
    'from_name' => (string) env('MAIL_FROM_NAME', env('APP_NAME', 'HudumaDesk')),
    'log_path' => BASE_PATH . '/storage/logs/mail.log',
];
