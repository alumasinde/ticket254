<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Services\Tenants\TenantContext;

final class TicketMessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(
        int $tenantId,
        int $ticketId,
        int $authorUserId,
        string $authorName,
        string $visibility,
        string $body,
    ): int {
        return $this->db->table('ticket_messages')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'author_user_id' => $authorUserId,
            'author_name' => $authorName,
            'visibility' => $visibility,
            'source' => 'staff',
            'body' => $body,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(TenantContext $ctx, int $ticketId, int $id): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE m.tenant_id = ? AND m.ticket_id = ? AND m.id = ? LIMIT 1',
            [$ctx->tenantId, $ticketId, $id],
        );
    }

    public function createRequester(int $tenantId, int $ticketId, string $authorName, string $body): int
    {
        return $this->db->table('ticket_messages')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'author_user_id' => null,
            'author_name' => $authorName,
            'visibility' => 'public',
            'source' => 'requester',
            'body' => $body,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublic(int $tenantId, int $ticketId, int $id): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE m.tenant_id = ? AND m.ticket_id = ? AND m.id = ? AND m.visibility = ? LIMIT 1',
            [$tenantId, $ticketId, $id, 'public'],
        );
    }

    /**
     * Public-safe conversation: internal notes are never selected.
     *
     * @return list<array<string, mixed>>
     */
    public function publicForTicket(int $tenantId, int $ticketId): array
    {
        return $this->db->select(
            'SELECT m.id, m.source, m.body, m.created_at FROM ticket_messages m '
            . 'WHERE m.tenant_id = ? AND m.ticket_id = ? AND m.visibility = ? '
            . 'ORDER BY m.created_at ASC, m.id ASC',
            [$tenantId, $ticketId, 'public'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTicket(TenantContext $ctx, int $ticketId): array
    {
        return $this->db->select(
            $this->selectSql()
            . ' WHERE m.tenant_id = ? AND m.ticket_id = ? ORDER BY m.created_at ASC, m.id ASC',
            [$ctx->tenantId, $ticketId],
        );
    }

    public function markFirstResponse(int $tenantId, int $ticketId): void
    {
        $this->db->statement(
            'UPDATE tickets SET first_response_at = COALESCE(first_response_at, UTC_TIMESTAMP()) '
            . 'WHERE tenant_id = ? AND id = ?',
            [$tenantId, $ticketId],
        );
    }

    private function selectSql(): string
    {
        return 'SELECT m.id, m.visibility, m.source, m.body, m.created_at, m.author_user_id, m.author_name, '
            . 'u.first_name AS author_first_name, u.last_name AS author_last_name '
            . 'FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_user_id';
    }
}
