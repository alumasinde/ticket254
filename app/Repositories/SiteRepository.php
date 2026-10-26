<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Tenants\TenantContext;

final class SiteRepository extends TenantScopedRepository
{
    protected string $table = 'sites';

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
    public function listWithCounts(TenantContext $ctx): array
    {
        return $this->db->select(
            'SELECT s.id, s.name, s.address, s.status, s.created_at, '
            . '(SELECT COUNT(*) FROM teams t WHERE t.tenant_id = s.tenant_id AND t.site_id = s.id) AS team_count '
            . 'FROM sites s WHERE s.tenant_id = ? ORDER BY s.status ASC, s.name ASC',
            [$ctx->tenantId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithCount(TenantContext $ctx, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT s.id, s.name, s.address, s.status, s.created_at, '
            . '(SELECT COUNT(*) FROM teams t WHERE t.tenant_id = s.tenant_id AND t.site_id = s.id) AS team_count '
            . 'FROM sites s WHERE s.tenant_id = ? AND s.id = ? LIMIT 1',
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
}
