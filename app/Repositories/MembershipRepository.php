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
            . 'r.code AS role_code, t.status AS tenant_status, t.timezone AS tenant_timezone, '
            . 'u.status AS user_status, u.auth_version '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'INNER JOIN tenants t ON t.id = tm.tenant_id '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$tenantId, $userId],
        );
    }

    /**
     * @return list<int>
     */
    public function teamIdsForUser(int $tenantId, int $userId): array
    {
        $rows = $this->db->select(
            'SELECT team_id FROM team_members WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId],
        );

        return array_map(static fn (array $row): int => (int) $row['team_id'], $rows);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function activeMembersForTenant(int $tenantId): array
    {
        $rows = $this->db->select(
            'SELECT u.id, u.first_name, u.last_name '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'WHERE tm.tenant_id = ? AND tm.status = ? AND u.status = ? '
            . 'ORDER BY u.first_name, u.last_name, u.id',
            [$tenantId, 'active', 'active'],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        return $this->db->select(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.status AS user_status, '
            . 'u.is_platform_admin, tm.role_id, tm.status AS membership_status, tm.joined_at, '
            . 'r.code AS role_code, r.name AS role_name, '
            . '(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \') '
            . ' FROM team_members tmem INNER JOIN teams t '
            . ' ON t.tenant_id = tmem.tenant_id AND t.id = tmem.team_id '
            . ' WHERE tmem.tenant_id = tm.tenant_id AND tmem.user_id = u.id) AS teams '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? '
            . 'ORDER BY tm.status ASC, u.first_name ASC, u.last_name ASC',
            [$tenantId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $tenantId, int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.status AS user_status, '
            . 'u.is_platform_admin, tm.role_id, tm.status AS membership_status, tm.joined_at, '
            . 'r.code AS role_code, r.name AS role_name, '
            . '(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \') '
            . ' FROM team_members tmem INNER JOIN teams t '
            . ' ON t.tenant_id = tmem.tenant_id AND t.id = tmem.team_id '
            . ' WHERE tmem.tenant_id = tm.tenant_id AND tmem.user_id = u.id) AS teams '
            . 'FROM tenant_memberships tm '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ? LIMIT 1',
            [$tenantId, $userId],
        );
    }

    public function isActiveMember(int $tenantId, int $userId): bool
    {
        return $this->db->table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->count() > 0;
    }

    public function updateRole(int $tenantId, int $userId, int $roleId): void
    {
        $this->db->table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update(['role_id' => $roleId]);
    }

    public function updateStatus(int $tenantId, int $userId, string $status): void
    {
        $update = ['status' => $status];

        if ($status === 'active') {
            $update['joined_at'] = gmdate('Y-m-d H:i:s');
        }

        $this->db->table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->update($update);
    }

    public function activeOwnerCount(int $tenantId): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS aggregate FROM tenant_memberships tm '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.status = ? AND r.code = ?',
            [$tenantId, 'active', 'owner'],
        )['aggregate'] ?? 0);
    }

    public function removeFromTeams(int $tenantId, int $userId): void
    {
        $this->db->table('team_members')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * @param list<int> $teamIds
     */
    public function addToTeams(int $tenantId, int $userId, array $teamIds): void
    {
        foreach (array_unique($teamIds) as $teamId) {
            if ($this->db->table('team_members')
                ->where('tenant_id', $tenantId)
                ->where('team_id', $teamId)
                ->where('user_id', $userId)
                ->count() > 0
            ) {
                continue;
            }

            $this->db->table('team_members')->insert([
                'tenant_id' => $tenantId,
                'team_id' => $teamId,
                'user_id' => $userId,
            ]);
        }
    }
}
