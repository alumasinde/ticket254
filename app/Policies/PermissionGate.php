<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Container;
use App\Core\Gate;
use App\Core\Request;
use App\Services\Tenants\TenantContext;

final class PermissionGate implements Gate
{
    public function __construct(private readonly Container $container)
    {
    }

    public function allows(Request $request, string $permission): bool
    {
        if (!$this->container->hasInstance(TenantContext::class)) {
            return false;
        }

        return $this->container->get(TenantContext::class)->can($permission);
    }
}
