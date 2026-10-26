<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketIsolationTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    /** @var array{tenant_id: int, user_id: int, name: string, slug: string, email: string, password: string} */
    private array $glee;

    /** @var array{tenant_id: int, user_id: int, name: string, slug: string, email: string, password: string} */
    private array $savannah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->glee = $this->registerWorkspaceFixture('Glee Hotel');
        $this->createTicketViaApi(['subject' => 'Glee lobby lights']);

        $this->savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $this->createTicketViaApi(['subject' => 'Savannah pool pump']);
        $this->createTicketViaApi(['subject' => 'Savannah breakfast stock']);

        $this->loginAs($this->glee);
    }

    public function test_list_returns_only_the_session_workspace_tickets(): void
    {
        $body = $this->listTicketsViaApi();

        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('Glee lobby lights', $body['data'][0]['subject']);
        $this->assertStringNotContainsString('Savannah pool pump', json_encode($body, JSON_THROW_ON_ERROR));

        $page = $this->get('/app/tickets');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Glee lobby lights', $page->content());
        $this->assertStringNotContainsString('Savannah pool pump', $page->content());
        $this->assertStringNotContainsString('Savannah breakfast stock', $page->content());
    }

    public function test_another_workspace_ticket_reference_is_not_found(): void
    {
        $foreign = $this->get('/api/v1/tickets/HD-000002', ['Accept' => 'application/json']);

        $this->assertSame(404, $foreign->status());
        $this->assertSame('not_found', $this->jsonBody($foreign)['error']['code']);
        $this->assertStringNotContainsString('Savannah breakfast stock', $foreign->content());

        $page = $this->get('/app/tickets/HD-000002');
        $this->assertSame(404, $page->status());
        $this->assertStringNotContainsString('Savannah breakfast stock', $page->content());

        $update = $this->request('PATCH', '/api/v1/tickets/HD-000002', [
            'version' => 1,
            'subject' => 'Cross-tenant write',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(404, $update->status());
        $this->assertSame('not_found', $this->jsonBody($update)['error']['code']);

        $row = $this->db()->table('tickets')
            ->where('tenant_id', $this->savannah['tenant_id'])
            ->where('ticket_number', 2)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Savannah breakfast stock', $row['subject']);
    }

    public function test_filters_cannot_reach_another_workspace_reference_data(): void
    {
        $foreignTeam = $this->teamId($this->savannah['tenant_id'], 'IT');
        $foreignCategory = $this->categoryId($this->savannah['tenant_id'], 'Wi-Fi/Network');

        $this->assertSame(0, $this->listTicketsViaApi(['team_id' => $foreignTeam])['meta']['total']);
        $this->assertSame(0, $this->listTicketsViaApi(['category_id' => $foreignCategory])['meta']['total']);

        $this->assertSame(1, $this->listTicketsViaApi(['status' => 'open'])['meta']['total']);
    }

    public function test_update_cannot_attach_another_workspace_category_or_site(): void
    {
        $foreignCategory = $this->categoryId($this->savannah['tenant_id'], 'Wi-Fi/Network');
        $foreignSite = $this->db()->table('sites')->insert([
            'tenant_id' => $this->savannah['tenant_id'],
            'name' => 'Savannah Annex',
        ]);

        $categoryResponse = $this->request('PATCH', '/api/v1/tickets/HD-000001', [
            'version' => 1,
            'category_id' => $foreignCategory,
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $categoryResponse->status());
        $this->assertArrayHasKey('category_id', $this->jsonBody($categoryResponse)['error']['fields']);

        $siteResponse = $this->request('PATCH', '/api/v1/tickets/HD-000001', [
            'version' => 1,
            'site_id' => $foreignSite,
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $siteResponse->status());
        $this->assertArrayHasKey('site_id', $this->jsonBody($siteResponse)['error']['fields']);

        $row = $this->db()->table('tickets')
            ->where('tenant_id', $this->glee['tenant_id'])
            ->where('ticket_number', 1)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row['category_id']);
        $this->assertNull($row['site_id']);
        $this->assertSame(1, (int) $row['version']);
    }

    public function test_an_agent_sees_team_and_assigned_tickets_only(): void
    {
        $network = $this->categoryId($this->glee['tenant_id'], 'Wi-Fi/Network');
        $housekeeping = $this->categoryId($this->glee['tenant_id'], 'Housekeeping');

        $laptop = $this->createTicketViaApi([
            'category_id' => $network,
            'subject' => 'Laptop will not boot',
        ]);
        $towels = $this->createTicketViaApi([
            'category_id' => $housekeeping,
            'subject' => 'Towels for room 9',
        ]);

        $agent = $this->addMember($this->glee['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($this->glee['tenant_id'], $this->teamId($this->glee['tenant_id'], 'IT'), $agent['user_id']);

        $this->loginAs($agent);

        $body = $this->listTicketsViaApi();
        $subjects = array_column($body['data'], 'subject');

        $this->assertContains('Laptop will not boot', $subjects);
        $this->assertNotContains('Towels for room 9', $subjects);
        $this->assertNotContains('Glee lobby lights', $subjects);
        $this->assertStringNotContainsString('Savannah pool pump', json_encode($body, JSON_THROW_ON_ERROR));

        $this->assertSame(200, $this->get('/api/v1/tickets/' . $laptop['reference'], [
            'Accept' => 'application/json',
        ])->status());

        $hidden = $this->get('/api/v1/tickets/' . $towels['reference'], ['Accept' => 'application/json']);
        $this->assertSame(404, $hidden->status());
        $this->assertSame(404, $this->get('/app/tickets/' . $towels['reference'])->status());

        $this->db()->table('tickets')
            ->where('tenant_id', $this->glee['tenant_id'])
            ->where('ticket_number', \App\Support\TicketReference::parse($towels['reference']))
            ->update(['assigned_user_id' => $agent['user_id']]);

        $assigned = $this->listTicketsViaApi();
        $this->assertContains('Towels for room 9', array_column($assigned['data'], 'subject'));

        $detail = $this->get('/api/v1/tickets/' . $towels['reference'], ['Accept' => 'application/json']);
        $this->assertSame(200, $detail->status());
        $this->assertSame('Brian Kiptoo', $this->jsonBody($detail)['data']['assigned_user']['name']);

        $updated = $this->patchTicketViaApi($towels['reference'], [
            'version' => 1,
            'subject' => 'Towels delivered to room 9',
        ]);
        $this->assertSame('Towels delivered to room 9', $updated['subject']);
    }
}
