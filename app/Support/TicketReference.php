<?php

declare(strict_types=1);

namespace App\Support;

final class TicketReference
{
    public static function format(int $number): string
    {
        return 'HD-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public static function parse(string $reference): ?int
    {
        if (preg_match('/^HD-(\d{1,18})$/i', trim($reference), $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        return $number > 0 ? $number : null;
    }
}
