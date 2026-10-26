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
}
