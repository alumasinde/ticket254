<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class NotificationOutboxRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        int $tenantId,
        ?int $ticketId,
        string $eventType,
        string $recipientEmail,
        ?string $recipientName,
        array $payload,
    ): int {
        return $this->db->table('notification_outbox')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'channel' => 'email',
            'event_type' => $eventType,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'available_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
