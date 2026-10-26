<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Services\Tenants\TenantContext;

final class TicketEventRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
    public function record(
        int $tenantId,
        int $ticketId,
        ?int $actorUserId,
        string $eventType,
        ?array $oldValue = null,
        ?array $newValue = null,
    ): int {
        return $this->db->table('ticket_events')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'old_value' => $oldValue === null ? null : $this->json($oldValue),
            'new_value' => $newValue === null ? null : $this->json($newValue),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTicket(TenantContext $ctx, int $ticketId): array
    {
        return $this->db->select(
            'SELECT e.id, e.event_type, e.old_value, e.new_value, e.created_at, e.actor_user_id, '
            . 'u.first_name AS actor_first_name, u.last_name AS actor_last_name '
            . 'FROM ticket_events e '
            . 'LEFT JOIN users u ON u.id = e.actor_user_id '
            . 'WHERE e.tenant_id = ? AND e.ticket_id = ? '
            . 'ORDER BY e.created_at ASC, e.id ASC',
            [$ctx->tenantId, $ticketId],
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
