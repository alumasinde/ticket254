<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class TicketTokenRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Only the SHA-256 hash of the raw token is stored. The raw value is shown once.
     */
    public function create(int $tenantId, int $ticketId, string $rawToken): int
    {
        return $this->db->table('ticket_tokens')->insert([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => null,
        ]);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $rawToken): ?array
    {
        $row = $this->db->table('ticket_tokens')
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
            return null;
        }

        return $row;
    }

    public function touch(int $id): void
    {
        $this->db->statement(
            'UPDATE ticket_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?',
            [$id],
        );
    }

    public function revokeForTicket(int $tenantId, int $ticketId): void
    {
        $this->db->statement(
            'UPDATE ticket_tokens SET revoked_at = UTC_TIMESTAMP() '
            . 'WHERE tenant_id = ? AND ticket_id = ? AND revoked_at IS NULL',
            [$tenantId, $ticketId],
        );
    }
}
