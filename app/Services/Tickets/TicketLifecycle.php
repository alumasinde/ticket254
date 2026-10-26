<?php

declare(strict_types=1);

namespace App\Services\Tickets;

final class TicketLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'open' => ['assigned', 'in_progress', 'pending', 'closed'],
        'assigned' => ['in_progress', 'pending', 'open', 'resolved'],
        'in_progress' => ['pending', 'resolved', 'assigned'],
        'pending' => ['in_progress', 'assigned', 'resolved'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['in_progress'],
    ];

    /**
     * @return list<string>
     */
    public static function allowedFrom(string $code): array
    {
        return self::TRANSITIONS[$code] ?? [];
    }

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }
}
