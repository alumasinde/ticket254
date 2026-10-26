<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\RoleRepository;

trait BuildsTickets
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function ticketPayload(array $overrides = []): array
    {
        return array_replace([
            'requester_name' => 'Njeri Wambui',
            'requester_email' => 'njeri.wambui@example.co.ke',
            'requester_phone' => '+254700000001',
            'subject' => 'No hot water in room 204',
            'description' => "The guest reports cold water since 06:00.\nPlease check the boiler.",
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function createTicketViaApi(array $overrides = []): array
    {
        $response = $this->post('/api/v1/tickets', $this->ticketPayload($overrides), [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(201, $response->status(), $response->content());

        return $this->jsonBody($response)['data'];
    }

    protected function categoryId(int $tenantId, string $name): int
    {
        $row = $this->db()->table('categories')
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();

        $this->assertNotNull($row, "Category [$name] was not found.");

        return (int) $row['id'];
    }

    protected function teamId(int $tenantId, string $name): int
    {
        $row = $this->db()->table('teams')
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();

        $this->assertNotNull($row, "Team [$name] was not found.");

        return (int) $row['id'];
    }

    /**
     * @return array{user_id: int, email: string, password: string, first_name: string, last_name: string}
     */
    protected function addMember(
        int $tenantId,
        string $roleCode = 'agent',
        string $firstName = 'Brian',
        string $lastName = 'Kiptoo',
    ): array {
        $email = $this->uniqueEmail(strtolower($firstName));
        $password = 'a-long-passphrase';

        $userId = $this->db()->table('users')->insert([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active',
        ]);

        $this->db()->table('tenant_memberships')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $this->app->container()->get(RoleRepository::class)->idByCode($roleCode),
            'status' => 'active',
            'joined_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'user_id' => $userId,
            'email' => $email,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }

    protected function joinTeam(int $tenantId, int $teamId, int $userId): void
    {
        $this->db()->table('team_members')->insert([
            'tenant_id' => $tenantId,
            'team_id' => $teamId,
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ticketRow(int $tenantId, int $number): array
    {
        $row = $this->db()->table('tickets')
            ->where('tenant_id', $tenantId)
            ->where('ticket_number', $number)
            ->first();

        $this->assertNotNull($row, "Ticket [$number] was not found in workspace [$tenantId].");

        return $row;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    protected function listTicketsViaApi(array $query = []): array
    {
        $uri = '/api/v1/tickets' . ($query === [] ? '' : '?' . http_build_query($query));
        $response = $this->get($uri, ['Accept' => 'application/json']);

        $this->assertSame(200, $response->status(), $response->content());

        return $this->jsonBody($response);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function patchTicketViaApi(string $reference, array $data): array
    {
        $response = $this->request('PATCH', '/api/v1/tickets/' . $reference, $data, [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(200, $response->status(), $response->content());

        return $this->jsonBody($response)['data'];
    }
}
