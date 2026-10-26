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
}
