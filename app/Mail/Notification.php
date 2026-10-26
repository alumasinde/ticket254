<?php

declare(strict_types=1);

namespace App\Mail;

final class Notification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $ticketId,
        public readonly string $eventType,
        public readonly string $recipientEmail,
        public readonly ?string $recipientName,
        public readonly array $payload,
    ) {
    }
}
