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

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; "
            . "object-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
            . "form-action 'self'; connect-src 'self'",
    ],

    // Only enable behind HTTPS. Browsers ignore HSTS received over plain HTTP.
    'hsts' => (bool) env('SECURITY_HSTS', false),
];
