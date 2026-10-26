<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Response;
use App\Repositories\RoleRepository;
use App\Repositories\TenantRepository;

trait BuildsWorkspaces
{
    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return $prefix . '.' . bin2hex(random_bytes(6)) . '@example.co.ke';
    }

    protected function uniqueSlug(string $prefix = 'workspace'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(5));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Amina',
            'last_name' => 'Otieno',
            'email' => $this->uniqueEmail('amina'),
            'password' => 'a-long-passphrase',
            'workspace_name' => 'Glee Hotel',
            'slug' => $this->uniqueSlug('glee'),
            'timezone' => 'Africa/Nairobi',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{payload: array<string, mixed>, body: array<string, mixed>}
     */
    protected function registerViaApi(array $overrides = []): array
    {
        $payload = $this->registrationPayload($overrides);
        $response = $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json']);

        $this->assertSame(201, $response->status(), $response->content());

        return [
            'payload' => $payload,
            'body' => $this->jsonBody($response),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{tenant_id: int, user_id: int, name: string, slug: string, email: string, password: string}
     */
    protected function registerWorkspaceFixture(string $name, array $overrides = []): array
    {
        $prefix = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $result = $this->registerViaApi(array_replace([
            'workspace_name' => $name,
            'slug' => $this->uniqueSlug($prefix === '' ? 'workspace' : $prefix),
        ], $overrides));
        $data = $result['body']['data'];

        return [
            'tenant_id' => (int) $data['workspace']['id'],
            'user_id' => (int) $data['user']['id'],
            'name' => (string) $data['workspace']['name'],
            'slug' => (string) $data['workspace']['slug'],
            'email' => (string) $result['payload']['email'],
            'password' => (string) $result['payload']['password'],
        ];
    }

    /**
     * @param array{email: string, password: string} $fixture
     */
    protected function loginAs(array $fixture, ?string $password = null): Response
    {
        return $this->post('/api/v1/auth/login', [
            'email' => $fixture['email'],
            'password' => $password ?? $fixture['password'],
        ], ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function sessionAuth(): ?array
    {
        $auth = $this->session->get('_auth');

        return is_array($auth) ? $auth : null;
    }

    /**
     * Extra workspace for an existing user, without the starter template.
     */
    protected function addWorkspaceForUser(
        int $userId,
        string $name,
        string $roleCode = 'agent',
        ?string $joinedAt = null,
        string $tenantStatus = 'active',
        string $membershipStatus = 'active',
    ): int {
        $prefix = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $tenantId = $this->app->container()->get(TenantRepository::class)->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($prefix === '' ? 'workspace' : $prefix),
            'public_key' => bin2hex(random_bytes(16)),
            'status' => $tenantStatus,
            'timezone' => 'Africa/Nairobi',
        ]);

        $this->db()->table('tenant_memberships')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $this->app->container()->get(RoleRepository::class)->idByCode($roleCode),
            'status' => $membershipStatus,
            'joined_at' => $joinedAt ?? gmdate('Y-m-d H:i:s'),
        ]);

        return $tenantId;
    }
}
