<?php

declare(strict_types=1);

return [
    'session' => [
        'name' => (string) env('SESSION_NAME', 'hd_session'),
        'lifetime' => (int) env('SESSION_LIFETIME', 120),
        'absolute_lifetime' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),
        'secure' => (bool) env('SESSION_SECURE', false),
        'same_site' => 'Lax',
        'path' => '/',
        'save_path' => BASE_PATH . '/storage/framework/sessions',
    ],
];
