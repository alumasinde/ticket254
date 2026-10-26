<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class MembershipRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $tenantId, int $userId, int $roleId): void
    {
        $this->db->table('tenant_memberships')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'status' => 'active',
            'joined_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Active memberships in active workspaces, most recently joined first.
     *
     * @return list<array{id: int, name: string, slug: string, role: string}>
     */
    public function activeWorkspacesForUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT t.id, t.name, t.slug, r.code AS role '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN tenants t ON t.id = tm.tenant_id '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.user_id = ? AND tm.status = ? AND t.status = ? '
            . 'ORDER BY COALESCE(tm.joined_at, tm.created_at) DESC, tm.tenant_id ASC',
            [$userId, 'active', 'active'],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'role' => (string) $row['role'],
        ], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function membershipForUserAndTenant(int $tenantId, int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT tm.tenant_id, tm.user_id, tm.role_id, tm.status AS membership_status, '
            . 'r.code AS role_code, t.status AS tenant_status, '
            . 'u.status AS user_status, u.auth_version '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'INNER JOIN tenants t ON t.id = tm.tenant_id '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$tenantId, $userId],
        );
    }
}
