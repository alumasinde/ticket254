<?php

declare(strict_types=1);

namespace App\Services\Tenants;

final class TenantContext
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly string $role,
        public readonly string $timezone,
        private readonly array $permissions,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
