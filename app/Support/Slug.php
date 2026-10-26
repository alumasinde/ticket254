<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    private const RESERVED = [
        'app',
        'api',
        'admin',
        'platform',
        'login',
        'register',
        'track',
        'request',
        'invite',
        'www',
        'support',
        'help',
        'static',
        'assets',
    ];

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED, true);
    }
}
