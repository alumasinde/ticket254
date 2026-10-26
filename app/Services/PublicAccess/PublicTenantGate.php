<?php

declare(strict_types=1);

namespace App\Services\PublicAccess;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Support\TenantResolver;

final class PublicTenantGate
{
    public function __construct(private readonly TenantResolver $resolver)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(?string $host, ?string $identifier): array
    {
        $tenant = $this->resolver->resolvePublicTenant($host, $identifier);

        if ($tenant === null || (int) $tenant['public_form_enabled'] !== 1) {
            throw new NotFoundException();
        }

        if ((string) $tenant['status'] !== 'active') {
            throw new HttpException(403, 'This workspace is unavailable.', [], 'tenant_suspended');
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previousSlug(?string $identifier): ?array
    {
        return $this->resolver->historyTenant($identifier);
    }
}
