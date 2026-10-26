<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Repositories\MembershipRepository;
use App\Repositories\UserRepository;

final class LoginUser
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly SessionAuth $auth,
    ) {
    }

    /**
     * @return array{
     *     user: array<string, mixed>,
     *     workspaces: list<array{id: int, name: string, slug: string, role: string}>,
     *     active_workspace_id: ?int
     * }|null
     */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null
            || (string) $user['status'] !== 'active'
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            return null;
        }

        $userId = (int) $user['id'];
        $workspaces = $this->memberships->activeWorkspacesForUser($userId);
        $activeWorkspaceId = $workspaces[0]['id'] ?? null;

        $this->auth->start($userId, $activeWorkspaceId, (int) $user['auth_version']);
        $this->users->recordLogin($userId);

        return [
            'user' => $user,
            'workspaces' => $workspaces,
            'active_workspace_id' => $activeWorkspaceId,
        ];
    }
}
