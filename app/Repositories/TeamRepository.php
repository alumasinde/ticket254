<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Tenants\TenantContext;

final class TeamRepository extends TenantScopedRepository
{
    protected string $table = 'teams';

    /**
     * @return list<array<string, mixed>>
     */
    public function all(TenantContext $ctx): array
    {
        return $this->query($ctx)->orderBy('name')->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(TenantContext $ctx, int $id): ?array
    {
        return $this->query($ctx)->where('id', $id)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForTenant(int $tenantId): array
    {
        return $this->db->table($this->table)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        return $this->db->table($this->table)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithCounts(TenantContext $ctx): array    {
        return $this->db->select(
            'SELECT t.id, t.name, t.site_id, t.status, t.created_at, s.name AS site_name, '
            . '(SELECT COUNT(*) FROM team_members m WHERE m.tenant_id = t.tenant_id AND m.team_id = t.id) AS member_count '
            . 'FROM teams t LEFT JOIN sites s ON s.tenant_id = t.tenant_id AND s.id = t.site_id '
            . 'WHERE t.tenant_id = ? ORDER BY t.status ASC, t.name ASC',
            [$ctx->tenantId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function membersForTenant(TenantContext $ctx): array
    {
        return $this->db->select(
            'SELECT tm.team_id, u.id AS user_id, u.first_name, u.last_name '
            . 'FROM team_members tm '
            . 'INNER JOIN users u ON u.id = tm.user_id '
            . 'WHERE tm.tenant_id = ? '
            . 'ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC',
            [$ctx->tenantId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithCount(TenantContext $ctx, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT t.id, t.name, t.site_id, t.status, t.created_at, s.name AS site_name, '
            . '(SELECT COUNT(*) FROM team_members m WHERE m.tenant_id = t.tenant_id AND m.team_id = t.id) AS member_count '
            . 'FROM teams t LEFT JOIN sites s ON s.tenant_id = t.tenant_id AND s.id = t.site_id '
            . 'WHERE t.tenant_id = ? AND t.id = ? LIMIT 1',
            [$ctx->tenantId, $id],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(TenantContext $ctx, array $data): int
    {
        return $this->db->table($this->table)->insert(['tenant_id' => $ctx->tenantId] + $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(TenantContext $ctx, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->query($ctx)->where('id', $id)->update($data);
    }

    public function nameExists(TenantContext $ctx, string $name, ?int $exceptId = null): bool
    {
        $query = $this->query($ctx)->where('name', $name);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->count() > 0;
    }

    public function hasMember(TenantContext $ctx, int $teamId, int $userId): bool
    {
        return $this->db->table('team_members')
            ->where('tenant_id', $ctx->tenantId)
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->count() > 0;
    }

    public function addMember(TenantContext $ctx, int $teamId, int $userId): void
    {
        $this->db->table('team_members')->insert([
            'tenant_id' => $ctx->tenantId,
            'team_id' => $teamId,
            'user_id' => $userId,
        ]);
    }

    public function removeMember(TenantContext $ctx, int $teamId, int $userId): int
    {
        return $this->db->table('team_members')
            ->where('tenant_id', $ctx->tenantId)
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->delete();
    }
}
