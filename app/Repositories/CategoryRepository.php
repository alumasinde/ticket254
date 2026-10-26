<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Tenants\TenantContext;

final class CategoryRepository extends TenantScopedRepository
{
    protected string $table = 'categories';

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
    public function listWithDetails(TenantContext $ctx): array
    {
        return $this->db->select(
            'SELECT c.id, c.name, c.team_id, c.default_priority_id, c.status, c.created_at, '
            . 't.name AS team_name, p.code AS priority_code, p.label AS priority_label '
            . 'FROM categories c '
            . 'LEFT JOIN teams t ON t.tenant_id = c.tenant_id AND t.id = c.team_id '
            . 'LEFT JOIN ticket_priorities p ON p.id = c.default_priority_id '
            . 'WHERE c.tenant_id = ? ORDER BY c.status ASC, c.name ASC',
            [$ctx->tenantId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithDetails(TenantContext $ctx, int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT c.id, c.name, c.team_id, c.default_priority_id, c.status, c.created_at, '
            . 't.name AS team_name, p.code AS priority_code, p.label AS priority_label '
            . 'FROM categories c '
            . 'LEFT JOIN teams t ON t.tenant_id = c.tenant_id AND t.id = c.team_id '
            . 'LEFT JOIN ticket_priorities p ON p.id = c.default_priority_id '
            . 'WHERE c.tenant_id = ? AND c.id = ? LIMIT 1',
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
