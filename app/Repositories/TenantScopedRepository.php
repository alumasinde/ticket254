<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Database\QueryBuilder;
use App\Services\Tenants\TenantContext;

abstract class TenantScopedRepository
{
    protected string $table = '';

    public function __construct(protected readonly Database $db)
    {
    }

    protected function query(TenantContext $ctx): QueryBuilder
    {
        return $this->db->table($this->table)->where('tenant_id', $ctx->tenantId);
    }
}
