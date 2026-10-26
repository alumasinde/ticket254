<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\ValidationException;
use App\Database\Database;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketCounterRepository;
use App\Repositories\UserRepository;
use App\Services\WorkspaceTemplate;
use App\Support\Slug;
use PDOException;

final class RegisterWorkspace
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly TicketCounterRepository $counters,
        private readonly WorkspaceTemplate $template,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: array<string, mixed>, tenant: array<string, mixed>, role: string}
     */
    public function execute(array $data): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $workspaceName = trim((string) ($data['workspace_name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        $timezone = trim((string) ($data['timezone'] ?? ''));

        if ($timezone === '') {
            $timezone = 'Africa/Nairobi';
        }

        if (Slug::isReserved($slug)) {
            throw new ValidationException(['slug' => 'That workspace address is reserved.']);
        }

        $ownerRoleId = $this->roles->idByCode('owner');

        try {
            return $this->db->transaction(function () use (
                $firstName,
                $lastName,
                $email,
                $password,
                $workspaceName,
                $slug,
                $timezone,
                $ownerRoleId,
            ): array {
                $userId = $this->users->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);

                $tenantId = $this->tenants->create([
                    'name' => $workspaceName,
                    'slug' => $slug,
                    'public_key' => bin2hex(random_bytes(16)),
                    'timezone' => $timezone,
                    'contact_email' => $email,
                ]);

                $this->memberships->create($tenantId, $userId, $ownerRoleId);
                $this->counters->createForTenant($tenantId);
                $this->template->apply($tenantId);

                return [
                    'user' => [
                        'id' => $userId,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                    ],
                    'tenant' => [
                        'id' => $tenantId,
                        'name' => $workspaceName,
                        'slug' => $slug,
                        'timezone' => $timezone,
                    ],
                    'role' => 'owner',
                ];
            });
        } catch (PDOException $exception) {
            $driverCode = $exception->errorInfo[1] ?? null;

            if ($exception->getCode() !== '23000' && $driverCode !== 1062) {
                throw $exception;
            }

            if (str_contains($exception->getMessage(), 'uq_users_email')) {
                throw new ValidationException(['email' => 'An account with this email already exists.']);
            }

            if (str_contains($exception->getMessage(), 'uq_tenants_slug')) {
                throw new ValidationException(['slug' => 'That workspace address is already taken.']);
            }

            throw $exception;
        }
    }
}
