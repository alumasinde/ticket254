<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Tenants\TenantContext;

final class MemberService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(TenantContext $ctx): array
    {
        return array_map($this->view(...), $this->memberships->listForTenant($ctx->tenantId));
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function roles(TenantContext $ctx): array
    {
        if ($ctx->role !== 'owner') {
            return array_values(array_filter(
                $this->roles->all(),
                static fn (array $role): bool => $role['code'] !== 'owner',
            ));
        }

        return $this->roles->all();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(TenantContext $ctx, int $userId, array $input, Request $request): array
    {
        $current = $this->membership($ctx, $userId);
        $hasRole = array_key_exists('role', $input);
        $hasStatus = array_key_exists('status', $input);

        if (!$hasRole && !$hasStatus) {
            throw new ValidationException(['member' => 'Choose a role or status to change.']);
        }

        if ($hasRole) {
            $this->changeRole($ctx, $userId, $current, (string) $input['role'], $request);
        }

        if ($hasStatus) {
            $this->changeStatus($ctx, $userId, $current, (string) $input['status'], $request);
        }

        return $this->view($this->membership($ctx, $userId));
    }

    /**
     * Removing a member disables their access and clears their teams. It never deletes
     * the global user or their ticket history.
     *
     * @return array<string, mixed>
     */
    public function remove(TenantContext $ctx, int $userId, Request $request): array
    {
        $current = $this->membership($ctx, $userId);
        $this->guardOwnerChange($ctx, $userId, $current, 'remove');

        if ((string) $current['membership_status'] === 'active') {
            $this->memberships->updateStatus($ctx->tenantId, $userId, 'disabled');
            $this->memberships->removeFromTeams($ctx->tenantId, $userId);
            $this->users->incrementAuthVersion($userId);
        }

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'member.removed', 'member', $userId, [
            'role' => (string) $current['role_code'],
        ]);

        return $this->view($this->membership($ctx, $userId));
    }

    /**
     * @param array<string, mixed> $current
     */
    private function changeRole(
        TenantContext $ctx,
        int $userId,
        array $current,
        string $role,
        Request $request,
    ): void {
        $role = strtolower(trim($role));

        if (!in_array($role, ['owner', 'admin', 'agent'], true)) {
            throw new ValidationException(['role' => 'Choose owner, admin or agent.']);
        }

        if ($role === (string) $current['role_code']) {
            return;
        }

        if ($role === 'owner' && $ctx->role !== 'owner') {
            throw new ValidationException(['role' => 'Only an owner can grant the owner role.']);
        }

        if ((string) $current['role_code'] === 'owner' && $ctx->role !== 'owner') {
            throw new ForbiddenException('Only an owner can change another owner\'s role.');
        }

        if ((string) $current['role_code'] === 'owner'
            && $role !== 'owner'
            && $this->memberships->activeOwnerCount($ctx->tenantId) <= 1
        ) {
            throw new ValidationException(['role' => 'The workspace needs at least one active owner.']);
        }

        $this->memberships->updateRole($ctx->tenantId, $userId, $this->roles->idByCode($role));

        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'member.role_changed', 'member', $userId, [
            'from' => (string) $current['role_code'],
            'to' => $role,
        ]);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function changeStatus(
        TenantContext $ctx,
        int $userId,
        array $current,
        string $status,
        Request $request,
    ): void {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ValidationException(['status' => 'Choose active or disabled.']);
        }

        if ($status === (string) $current['membership_status']) {
            return;
        }

        if ($status === 'disabled') {
            $this->guardOwnerChange($ctx, $userId, $current, 'disable');

            if ((string) $current['user_status'] !== 'active') {
                throw new ValidationException(['status' => 'This account is already disabled.']);
            }

            $this->memberships->updateStatus($ctx->tenantId, $userId, 'disabled');
            $this->users->incrementAuthVersion($userId);

            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'member.disabled', 'member', $userId);

            return;
        }

        if ((string) $current['user_status'] !== 'active') {
            throw new ValidationException(['status' => 'This account is disabled platform-wide.']);
        }

        $this->memberships->updateStatus($ctx->tenantId, $userId, 'active');
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'member.enabled', 'member', $userId);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function guardOwnerChange(TenantContext $ctx, int $userId, array $current, string $action): void
    {
        if ($userId === $ctx->userId) {
            throw new ValidationException(['status' => 'You cannot ' . $action . ' your own access.']);
        }

        if ((string) $current['role_code'] === 'owner' && $ctx->role !== 'owner') {
            throw new ForbiddenException('Only an owner can change another owner\'s access.');
        }

        if ((string) $current['role_code'] === 'owner'
            && (string) $current['membership_status'] === 'active'
            && $this->memberships->activeOwnerCount($ctx->tenantId) <= 1
        ) {
            throw new ValidationException(['status' => 'The workspace needs at least one active owner.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function membership(TenantContext $ctx, int $userId): array
    {
        $membership = $this->memberships->findDetailed($ctx->tenantId, $userId);

        if ($membership === null) {
            throw new NotFoundException();
        }

        return $membership;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        $teams = trim((string) ($row['teams'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role_code'],
            'role_name' => (string) $row['role_name'],
            'status' => (string) $row['membership_status'],
            'user_status' => (string) $row['user_status'],
            'teams' => $teams === '' ? [] : array_map('trim', explode(',', $teams)),
            'joined_at' => $this->iso($row['joined_at'] ?? null),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
