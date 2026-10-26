<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class TicketAttachmentRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $tenantId, int $ticketId, array $data): int
    {
        return $this->db->table('ticket_attachments')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'message_id' => $data['message_id'] ?? null,
            'storage_key' => (string) $data['storage_key'],
            'original_name' => (string) $data['original_name'],
            'mime_type' => (string) $data['mime_type'],
            'size_bytes' => (int) $data['size_bytes'],
            'checksum_sha256' => $data['checksum_sha256'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTicket(int $tenantId, int $ticketId): array
    {
        return $this->db->select(
            'SELECT id, original_name, mime_type, size_bytes, created_at '
            . 'FROM ticket_attachments WHERE tenant_id = ? AND ticket_id = ? ORDER BY id ASC',
            [$tenantId, $ticketId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $ticketId, int $id): ?array
    {
        return $this->db->table('ticket_attachments')
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->where('id', $id)
            ->first();
    }

    public function delete(int $tenantId, int $ticketId, int $id): int
    {
        return $this->db->table('ticket_attachments')
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->where('id', $id)
            ->delete();
    }
}
