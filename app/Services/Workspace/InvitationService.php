<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\InvitationRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionAuth;
use App\Services\Tenants\TenantContext;
use App\Support\RateLimiter;
use App\Database\Database;
use PDOException;

final class InvitationService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly Database $db,
        private readonly InvitationRepository $invitations,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly TeamRepository $teams,
        private readonly NotificationOutboxRepository $outbox,
        private readonly AuditLogger $audit,
        private readonly SessionAuth $auth,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(TenantContext $ctx): array
    {
        $teamNames = $this->teamNames($ctx);

        return array_map(
            fn (array $row): array => $this->view($row, $teamNames),
            $this->invitations->pendingForTenant($ctx->tenantId),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function invite(TenantContext $ctx, array $input, Request $request): array
    {
        $retry = $this->limiter->attempt(['invitations:tenant:' . $ctx->tenantId], 20, 3600);

        if ($retry !== null) {
            throw new HttpException(
                429,
                'Too many invitations. Try again later.',
                ['Retry-After' => (string) $retry],
                'rate_limited',
            );
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }

        $role = strtolower(trim((string) ($input['role'] ?? 'agent')));

        if (!in_array($role, ['owner', 'admin', 'agent'], true)) {
            throw new ValidationException(['role' => 'Choose owner, admin or agent.']);
        }

        if ($role === 'owner' && $ctx->role !== 'owner') {
            throw new ValidationException(['role' => 'Only an owner can invite another owner.']);
        }

        $teamIds = $this->teamIds($ctx, $input['team_ids'] ?? []);

        $existingUser = $this->users->findByEmail($email);

        if ($existingUser !== null
            && $this->memberships->isActiveMember($ctx->tenantId, (int) $existingUser['id'])
        ) {
            throw new ValidationException(['email' => 'This person is already a member of this workspace.']);
        }

        if ($this->invitations->pendingForEmail($ctx->tenantId, $email) !== null) {
            throw new ValidationException(['email' => 'An invitation is already pending for this email.']);
        }

        $teamNames = $this->teamNames($ctx);

        $invitation = $this->db->transaction(function () use (
            $ctx,
            $email,
            $role,
            $teamIds,
            $request,
            $teamNames,
        ): array {
            $token = bin2hex(random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + self::EXPIRY_DAYS * 86400);
            $id = $this->invitations->create([
                'tenant_id' => $ctx->tenantId,
                'email' => $email,
                'role_id' => $this->roles->idByCode($role),
                'team_ids' => $teamIds === []
                    ? null
                    : json_encode($teamIds, JSON_THROW_ON_ERROR),
                'token_hash' => hash('sha256', $token),
                'invited_by' => $ctx->userId,
                'expires_at' => $expiresAt,
            ]);

            $row = $this->invitations->findForTenant($ctx->tenantId, $id);

            if ($row === null) {
                throw new \RuntimeException('The invitation could not be created.');
            }

            $this->send($row, $token, $ctx, $teamNames);
            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'invitation.sent', 'invitation', $id, [
                'role' => $role,
                'team_count' => count($teamIds),
            ]);

            return $row + ['token' => $token];
        });

        return $this->view($invitation, $teamNames) + [
            'accept_url' => $this->acceptUrl((string) $invitation['token']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resend(TenantContext $ctx, int $id, Request $request): array
    {
        $teamNames = $this->teamNames($ctx);
        $current = $this->invitation($ctx, $id);

        if ($current['accepted_at'] !== null) {
            throw new ValidationException(['invitation' => 'This invitation was already accepted.']);
        }

        if ($current['revoked_at'] !== null) {
            throw new ValidationException(['invitation' => 'This invitation was revoked. Send a new invitation.']);
        }

        $token = bin2hex(random_bytes(32));

        $this->invitations->update($id, [
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::EXPIRY_DAYS * 86400),
            'accepted_at' => null,
            'revoked_at' => null,
        ]);

        $row = $this->invitation($ctx, $id);
        $this->send($row, $token, $ctx, $teamNames);
        $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'invitation.resent', 'invitation', $id);

        return $this->view($row, $teamNames) + ['accept_url' => $this->acceptUrl($token)];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(TenantContext $ctx, int $id, Request $request): array
    {
        $current = $this->invitation($ctx, $id);

        if ($current['accepted_at'] !== null) {
            throw new ValidationException(['invitation' => 'This invitation was already accepted.']);
        }

        if ($current['revoked_at'] === null) {
            $this->invitations->revoke($id);
            $this->audit->record($request, $ctx->tenantId, $ctx->userId, 'invitation.revoked', 'invitation', $id);
        }

        return $this->view($this->invitation($ctx, $id), $this->teamNames($ctx));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValid(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->invitations->findByHash(hash('sha256', $token));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{tenant_id: int, user_id: int, email: string, tenant_name: string, existing: bool}
     */
    public function accept(string $token, array $input, Request $request): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new HttpException(410, 'This invitation is invalid or has expired.', [], 'invitation_invalid');
        }

        $hash = hash('sha256', $token);

        try {
            $result = $this->db->transaction(function () use ($hash, $input, $request): array {
                $invitation = $this->invitations->findByHashForUpdate($hash);

                if ($invitation === null) {
                    throw new HttpException(410, 'This invitation is invalid or has expired.', [], 'invitation_invalid');
                }

                if ((string) $invitation['tenant_status'] !== 'active') {
                    throw new ForbiddenException('This workspace is unavailable.');
                }

                $email = strtolower((string) $invitation['email']);
                $existing = $this->users->findByEmail($email);
                $sessionUserId = $this->auth->userId();

                if ($existing !== null) {
                    if ((string) $existing['status'] !== 'active') {
                        throw new ForbiddenException('This account is disabled.');
                    }

                    if ($sessionUserId !== (int) $existing['id']) {
                        throw new ForbiddenException('Sign in with the invited email to accept this invitation.');
                    }

                    $userId = (int) $existing['id'];
                    $membership = $this->memberships->findDetailed((int) $invitation['tenant_id'], $userId);

                    if ($membership !== null && (string) $membership['membership_status'] === 'active') {
                        throw new ValidationException(['email' => 'You are already a member of this workspace.']);
                    }

                    if ($membership === null) {
                        $this->memberships->create(
                            (int) $invitation['tenant_id'],
                            $userId,
                            (int) $invitation['role_id'],
                        );
                    } else {
                        $this->memberships->updateRole((int) $invitation['tenant_id'], $userId, (int) $invitation['role_id']);
                        $this->memberships->updateStatus((int) $invitation['tenant_id'], $userId, 'active');
                    }
                } else {
                    if ($sessionUserId !== null) {
                        throw new ForbiddenException('Sign out before accepting this invitation.');
                    }

                    $firstName = trim((string) ($input['first_name'] ?? ''));
                    $lastName = trim((string) ($input['last_name'] ?? ''));
                    $password = (string) ($input['password'] ?? '');
                    $confirmation = $input['password_confirmation'] ?? null;

                    if ($firstName === '' || mb_strlen($firstName) > 100) {
                        throw new ValidationException(['first_name' => 'Enter your first name.']);
                    }

                    if ($lastName === '' || mb_strlen($lastName) > 100) {
                        throw new ValidationException(['last_name' => 'Enter your last name.']);
                    }

                    if (mb_strlen($password) < 10) {
                        throw new ValidationException(['password' => 'Use at least 10 characters.']);
                    }

                    if ($confirmation !== null && $confirmation !== $password) {
                        throw new ValidationException(['password_confirmation' => 'The passwords do not match.']);
                    }

                    $userId = $this->users->create([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    $this->memberships->create(
                        (int) $invitation['tenant_id'],
                        $userId,
                        (int) $invitation['role_id'],
                    );
                }

                $teamIds = $this->decodeTeamIds($invitation['team_ids'] ?? null);

                if ($teamIds !== []) {
                    $this->memberships->addToTeams((int) $invitation['tenant_id'], $userId, $teamIds);
                }

                if ($this->invitations->markAccepted((int) $invitation['id']) !== 1) {
                    throw new HttpException(410, 'This invitation is no longer valid.', [], 'invitation_invalid');
                }

                $this->audit->record(
                    $request,
                    (int) $invitation['tenant_id'],
                    $userId,
                    'invitation.accepted',
                    'invitation',
                    (int) $invitation['id'],
                    ['role' => (string) $invitation['role_code']],
                );

                return [
                    'tenant_id' => (int) $invitation['tenant_id'],
                    'user_id' => $userId,
                    'email' => $email,
                    'tenant_name' => (string) $invitation['tenant_name'],
                    'existing' => $existing !== null,
                ];
            });
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new ValidationException(['email' => 'An account with this email already exists. Sign in to accept.']);
            }

            throw $exception;
        }

        if (!$result['existing'] || $this->auth->userId() === $result['user_id']) {
            $user = $this->users->findById($result['user_id']);

            if ($user !== null) {
                $this->auth->start($result['user_id'], $result['tenant_id'], (int) $user['auth_version']);
            }
        }

        return $result;
    }

    /**
     * Public preview of an invitation. Returns null when the token is invalid or expired.
     *
     * @return array<string, mixed>|null
     */
    public function preview(string $token, ?int $sessionUserId): ?array
    {
        $invitation = $this->findValid($token);

        if ($invitation === null) {
            return null;
        }

        $existing = $this->users->findByEmail((string) $invitation['email']);
        $teamIds = $this->decodeTeamIds($invitation['team_ids'] ?? null);
        $teamNames = [];

        foreach ($this->teams->activeForTenant((int) $invitation['tenant_id']) as $team) {
            $teamNames[(int) $team['id']] = (string) $team['name'];
        }

        $inviter = trim(
            (string) ($invitation['inviter_first_name'] ?? '') . ' ' . (string) ($invitation['inviter_last_name'] ?? ''),
        );
        $expires = strtotime((string) $invitation['expires_at'] . ' UTC');

        return [
            'token' => $token,
            'email' => (string) $invitation['email'],
            'workspace_name' => (string) $invitation['tenant_name'],
            'role' => (string) $invitation['role_name'],
            'teams' => array_values(array_filter(array_map(
                static fn (int $id): ?string => $teamNames[$id] ?? null,
                $teamIds,
            ))),
            'inviter' => $inviter === '' ? null : $inviter,
            'expires_at' => $expires === false ? null : gmdate('Y-m-d\TH:i:s\Z', $expires),
            'existing_user' => $existing !== null,
            'signed_in' => $sessionUserId !== null,
            'can_accept' => $existing !== null && $sessionUserId === (int) $existing['id'],
            'must_sign_in' => $existing !== null && $sessionUserId !== (int) $existing['id'],
            'workspace_unavailable' => (string) $invitation['tenant_status'] !== 'active',
        ];
    }

    /**
     * @param array<string, mixed> $invitation
     * @param array<int, string> $teamNames
     */
    private function send(array $invitation, string $token, TenantContext $ctx, array $teamNames): void
    {
        $inviter = $this->users->findById($ctx->userId);
        $inviterName = $inviter === null
            ? 'A teammate'
            : trim((string) $inviter['first_name'] . ' ' . (string) $inviter['last_name']);
        $teamIds = $this->decodeTeamIds($invitation['team_ids'] ?? null);
        $names = array_values(array_filter(array_map(
            static fn (int $id): ?string => $teamNames[$id] ?? null,
            $teamIds,
        )));

        $expires = strtotime((string) $invitation['expires_at'] . ' UTC');

        $this->outbox->enqueue(
            $ctx->tenantId,
            null,
            'invitation',
            (string) $invitation['email'],
            null,
            [
                'workspace_name' => (string) $invitation['tenant_name'],
                'role' => (string) $invitation['role_name'],
                'inviter_name' => $inviterName,
                'teams' => implode(', ', $names),
                'accept_url' => $this->acceptUrl($token),
                'expires_at' => $expires === false ? '' : date('j M Y', $expires),
            ],
        );
    }

    /**
     * @param array<string, mixed> $invitation
     * @param array<int, string> $teamNames
     * @return array<string, mixed>
     */
    private function view(array $invitation, array $teamNames): array
    {
        $teamIds = $this->decodeTeamIds($invitation['team_ids'] ?? null);
        $expiresAt = $this->iso($invitation['expires_at'] ?? null);
        $expiresTimestamp = is_string($invitation['expires_at'] ?? null)
            ? strtotime((string) $invitation['expires_at'] . ' UTC')
            : false;
        $inviter = trim(
            (string) ($invitation['inviter_first_name'] ?? '') . ' ' . (string) ($invitation['inviter_last_name'] ?? ''),
        );

        return [
            'id' => (int) $invitation['id'],
            'email' => (string) $invitation['email'],
            'role' => (string) $invitation['role_code'],
            'role_name' => (string) $invitation['role_name'],
            'team_ids' => $teamIds,
            'teams' => array_values(array_filter(array_map(
                static fn (int $id): ?string => $teamNames[$id] ?? null,
                $teamIds,
            ))),
            'invited_by' => $inviter === '' ? null : $inviter,
            'status' => $invitation['accepted_at'] !== null
                ? 'accepted'
                : ($invitation['revoked_at'] !== null
                    ? 'revoked'
                    : ($expiresTimestamp !== false && $expiresTimestamp < time() ? 'expired' : 'pending')),
            'expires_at' => $expiresAt,
            'created_at' => $this->iso($invitation['created_at'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(TenantContext $ctx, int $id): array
    {
        $invitation = $this->invitations->findForTenant($ctx->tenantId, $id);

        if ($invitation === null) {
            throw new \App\Core\Exceptions\NotFoundException();
        }

        return $invitation;
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function teamIds(TenantContext $ctx, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            throw new ValidationException(['team_ids' => 'Choose teams for the invitation.']);
        }

        $ids = [];

        foreach ($value as $id) {
            if (!is_scalar($id) || preg_match('/^\d+$/', (string) $id) !== 1) {
                throw new ValidationException(['team_ids' => 'Choose valid teams.']);
            }

            $id = (int) $id;
            $team = $this->teams->findForTenant($ctx->tenantId, $id);

            if ($team === null || (string) $team['status'] !== 'active') {
                throw new ValidationException(['team_ids' => 'Choose teams from this workspace.']);
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, string>
     */
    private function teamNames(TenantContext $ctx): array
    {
        $names = [];

        foreach ($this->teams->listWithCounts($ctx) as $team) {
            $names[(int) $team['id']] = (string) $team['name'];
        }

        return $names;
    }

    /**
     * @return list<int>
     */
    private function decodeTeamIds(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0));
    }

    private function acceptUrl(string $token): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/invite/' . $token;
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
