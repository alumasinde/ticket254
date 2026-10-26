<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Repositories\MembershipRepository;
use App\Services\Tenants\TenantContext;

final class TicketAccess
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function canView(TenantContext $ctx, array $ticket): bool
    {
        if ($ctx->can('tickets.view_all')) {
            return true;
        }

        if ((int) ($ticket['assigned_user_id'] ?? 0) === $ctx->userId) {
            return true;
        }

        $teamId = $ticket['team_id'] ?? null;

        return $teamId !== null
            && in_array((int) $teamId, $this->memberships->teamIdsForUser($ctx->tenantId, $ctx->userId), true);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function canEdit(TenantContext $ctx, array $ticket): bool
    {
        if ($ctx->can('tickets.view_all')) {
            return true;
        }

        return (int) ($ticket['assigned_user_id'] ?? 0) === $ctx->userId;
    }
}
