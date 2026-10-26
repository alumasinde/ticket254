<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class InvitationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->table('invitations')->insert($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE i.tenant_id = ? AND i.id = ? LIMIT 1',
            [$tenantId, $id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForTenant(int $tenantId): array
    {
        return $this->db->select(
            $this->selectSql()
            . ' WHERE i.tenant_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL'
            . ' ORDER BY i.created_at DESC, i.id DESC',
            [$tenantId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingForEmail(int $tenantId, string $email): ?array
    {
        return $this->db->selectOne(
            $this->selectSql()
            . ' WHERE i.tenant_id = ? AND i.email = ? AND i.accepted_at IS NULL'
            . ' AND i.revoked_at IS NULL AND i.expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$tenantId, $email],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        return $this->db->selectOne(
            $this->selectSql()
            . ' WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL'
            . ' AND i.expires_at > UTC_TIMESTAMP() LIMIT 1',
            [$hash],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHashForUpdate(string $hash): ?array
    {
        return $this->db->selectOne(
            $this->selectSql()
            . ' WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL'
            . ' AND i.expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE',
            [$hash],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->db->table('invitations')->where('id', $id)->update($data);
    }

    public function markAccepted(int $id): int
    {
        return $this->db->table('invitations')
            ->where('id', $id)
            ->whereNull('accepted_at')
            ->update(['accepted_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function revoke(int $id): int
    {
        return $this->db->table('invitations')
            ->where('id', $id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    private function selectSql(): string
    {
        return 'SELECT i.id, i.tenant_id, i.email, i.role_id, i.team_ids, i.token_hash, '
            . 'i.invited_by, i.expires_at, i.accepted_at, i.revoked_at, i.created_at, '
            . 'r.code AS role_code, r.name AS role_name, '
            . 't.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status, '
            . 'u.first_name AS inviter_first_name, u.last_name AS inviter_last_name '
            . 'FROM invitations i '
            . 'INNER JOIN roles r ON r.id = i.role_id '
            . 'INNER JOIN tenants t ON t.id = i.tenant_id '
            . 'LEFT JOIN users u ON u.id = i.invited_by';
    }
}
