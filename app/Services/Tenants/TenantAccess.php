<?php

declare(strict_types=1);

namespace App\Services\Tenants;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\HttpException;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;

final class TenantAccess
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
    ) {
    }

    public function contextFor(int $userId, int $tenantId, int $authVersion): TenantContext
    {
        $row = $this->memberships->membershipForUserAndTenant($tenantId, $userId);

        if ($row === null) {
            throw new ForbiddenException('You do not have access to this workspace.');
        }

        if ((string) $row['user_status'] !== 'active'
            || (int) $row['auth_version'] !== $authVersion
        ) {
            throw new HttpException(
                401,
                'Your session is no longer valid. Please sign in again.',
                [],
                'unauthenticated',
            );
        }

        if ((string) $row['tenant_status'] !== 'active') {
            throw new HttpException(
                403,
                'This workspace is unavailable.',
                [],
                'tenant_suspended',
            );
        }

        if ((string) $row['membership_status'] !== 'active') {
            throw new ForbiddenException('Your access to this workspace has been disabled.');
        }

        $roleId = (int) $row['role_id'];

        return new TenantContext(
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (string) $row['role_code'],
            $this->roles->permissionsForRole($roleId),
        );
    }
}
