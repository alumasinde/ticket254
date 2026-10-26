<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketDetailTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    /**
     * @return array{fixture: array<string, mixed>, ticket: array<string, mixed>}
     */
    private function workspaceWithTicket(): array
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $categoryId = $this->categoryId($fixture['tenant_id'], 'Wi-Fi/Network');

        $ticket = $this->createTicketViaApi([
            'category_id' => $categoryId,
            'requester_phone' => '+254711223344',
            'description' => "Guest cannot connect in room 204.\nRouter light is red.",
        ]);

        return ['fixture' => $fixture, 'ticket' => $ticket];
    }

    public function test_api_detail_returns_the_full_ticket_and_history(): void
    {
        ['ticket' => $ticket] = $this->workspaceWithTicket();

        $response = $this->get('/api/v1/tickets/' . $ticket['reference'], ['Accept' => 'application/json']);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame($ticket['reference'], $data['reference']);
        $this->assertSame(1, $data['version']);
        $this->assertSame('+254711223344', $data['requester']['phone']);
        $this->assertStringContainsString('Router light is red.', $data['description']);
        $this->assertCount(1, $data['events']);
        $this->assertSame('Ticket created', $data['events'][0]['summary']);
        $this->assertSame('Amina Otieno', $data['events'][0]['actor']['name']);

        $lowercase = $this->get('/api/v1/tickets/' . strtolower($ticket['reference']), [
            'Accept' => 'application/json',
        ]);
        $this->assertSame(200, $lowercase->status());
        $this->assertSame($ticket['reference'], $this->jsonBody($lowercase)['data']['reference']);
    }

    public function test_detail_is_only_resolved_by_a_valid_reference(): void
    {
        $this->workspaceWithTicket();

        foreach (['1', 'HD-ABC', 'HD-0', 'HD-999999'] as $reference) {
            $response = $this->get('/api/v1/tickets/' . $reference, ['Accept' => 'application/json']);

            $this->assertSame(404, $response->status(), $reference);
            $this->assertSame('not_found', $this->jsonBody($response)['error']['code'], $reference);
        }
    }

    public function test_api_update_bumps_the_version_and_appends_an_event(): void
    {
        ['fixture' => $fixture, 'ticket' => $ticket] = $this->workspaceWithTicket();
        $housekeeping = $this->categoryId($fixture['tenant_id'], 'Housekeeping');
        $siteId = $this->db()->table('sites')->insert([
            'tenant_id' => $fixture['tenant_id'],
            'name' => 'Glee Main Building',
        ]);

        $updated = $this->patchTicketViaApi($ticket['reference'], [
            'version' => 1,
            'subject' => 'No Wi-Fi in room 204',
            'description' => 'Guest cannot connect. Router was rebooted.',
            'category_id' => $housekeeping,
            'site_id' => $siteId,
        ]);

        $this->assertSame(2, $updated['version']);
        $this->assertSame('No Wi-Fi in room 204', $updated['subject']);
        $this->assertSame('Housekeeping', $updated['category']['name']);
        $this->assertSame('Glee Main Building', $updated['site']['name']);
        $this->assertCount(2, $updated['events']);
        $this->assertSame('Details edited: subject, description, category id, site id', $updated['events'][1]['summary']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame(2, (int) $row['version']);

        $edited = $this->db()->table('ticket_events')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'edited')
            ->first();

        $this->assertNotNull($edited);
        $this->assertSame($fixture['user_id'], (int) $edited['actor_user_id']);

        $newValue = json_decode((string) $edited['new_value'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('No Wi-Fi in room 204', $newValue['subject']);
        $this->assertSame(['subject', 'description', 'category_id', 'site_id'], $newValue['changed']);
    }

    public function test_stale_version_returns_409_with_the_current_version(): void
    {
        ['fixture' => $fixture, 'ticket' => $ticket] = $this->workspaceWithTicket();

        $this->patchTicketViaApi($ticket['reference'], [
            'version' => 1,
            'subject' => 'First writer wins',
        ]);

        $response = $this->request('PATCH', '/api/v1/tickets/' . $ticket['reference'], [
            'version' => 1,
            'subject' => 'Stale writer loses',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(409, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('version_conflict', $body['error']['code']);
        $this->assertSame('This ticket was updated by someone else. Reload and try again.', $body['error']['message']);
        $this->assertSame(2, $body['error']['current_version']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame('First writer wins', $row['subject']);
        $this->assertSame(2, (int) $row['version']);

        $edits = $this->db()->table('ticket_events')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'edited')
            ->count();
        $this->assertSame(1, $edits);
    }

    public function test_update_requires_a_valid_version(): void
    {
        ['ticket' => $ticket] = $this->workspaceWithTicket();

        $missing = $this->request('PATCH', '/api/v1/tickets/' . $ticket['reference'], [
            'subject' => 'No version supplied',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $missing->status());
        $this->assertSame('validation_failed', $this->jsonBody($missing)['error']['code']);
        $this->assertArrayHasKey('version', $this->jsonBody($missing)['error']['fields']);

        $zero = $this->request('PATCH', '/api/v1/tickets/' . $ticket['reference'], [
            'version' => 0,
            'subject' => 'Zero version',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $zero->status());
    }

    public function test_page_renders_the_ticket_history_and_an_edit_form_for_editors(): void
    {
        ['ticket' => $ticket] = $this->workspaceWithTicket();

        $page = $this->get('/app/tickets/' . $ticket['reference']);

        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString($ticket['reference'], $content);
        $this->assertStringContainsString('No hot water in room 204', $content);
        $this->assertStringContainsString('Ticket created', $content);
        $this->assertStringContainsString('Amina Otieno', $content);
        $this->assertStringContainsString('Edit ticket', $content);
        $this->assertStringContainsString('Edit details', $content);
        $this->assertStringContainsString('name="version" value="1"', $content);
        $this->assertStringContainsString('name="_method" value="PATCH"', $content);
    }

    public function test_page_edit_updates_the_ticket_and_flashes_success(): void
    {
        ['ticket' => $ticket] = $this->workspaceWithTicket();

        $response = $this->post('/app/tickets/' . $ticket['reference'], [
            '_method' => 'PATCH',
            '_token' => csrf_token(),
            'version' => '1',
            'subject' => 'Hot water restored request',
            'description' => 'Boiler technician is on the way.',
            'category_id' => '',
            'site_id' => '',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/app/tickets/' . $ticket['reference'], $response->header('Location'));

        $follow = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(200, $follow->status());
        $this->assertStringContainsString('Ticket updated.', $follow->content());
        $this->assertStringContainsString('Hot water restored request', $follow->content());
        $this->assertStringContainsString('name="version" value="2"', $follow->content());
    }

    public function test_page_edit_reports_a_stale_version_without_overwriting(): void
    {
        ['fixture' => $fixture, 'ticket' => $ticket] = $this->workspaceWithTicket();

        $this->patchTicketViaApi($ticket['reference'], [
            'version' => 1,
            'subject' => 'Changed elsewhere',
        ]);

        $response = $this->post('/app/tickets/' . $ticket['reference'], [
            '_method' => 'PATCH',
            '_token' => csrf_token(),
            'version' => '1',
            'subject' => 'Stale page submit',
            'description' => 'This should not be saved.',
            'category_id' => '',
            'site_id' => '',
        ]);

        $this->assertSame(302, $response->status());

        $follow = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertStringContainsString('This ticket was updated by someone else.', $follow->content());
        $this->assertStringContainsString('Changed elsewhere', $follow->content());
        $this->assertStringNotContainsString('Stale page submit', $follow->content());

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame('Changed elsewhere', $row['subject']);
        $this->assertSame(2, (int) $row['version']);
    }

    public function test_a_team_member_can_view_but_not_edit_until_assigned(): void
    {
        ['fixture' => $fixture, 'ticket' => $ticket] = $this->workspaceWithTicket();
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($fixture['tenant_id'], $this->teamId($fixture['tenant_id'], 'IT'), $agent['user_id']);

        $this->loginAs($agent);

        $page = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(200, $page->status());
        $this->assertStringNotContainsString('Edit details', $page->content());

        $denied = $this->request('PATCH', '/api/v1/tickets/' . $ticket['reference'], [
            'version' => 1,
            'subject' => 'Agent changes without assignment',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(403, $denied->status());
        $this->assertSame('forbidden', $this->jsonBody($denied)['error']['code']);

        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 1)
            ->update(['assigned_user_id' => $agent['user_id']]);

        $allowed = $this->patchTicketViaApi($ticket['reference'], [
            'version' => 1,
            'subject' => 'Agent now owns this',
        ]);

        $this->assertSame('Agent now owns this', $allowed['subject']);
        $this->assertSame('Brian Kiptoo', $allowed['assigned_user']['name']);

        $after = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertStringContainsString('Edit details', $after->content());
    }

    public function test_a_bare_database_id_is_not_a_page_route(): void
    {
        $this->workspaceWithTicket();

        $page = $this->get('/app/tickets/1');

        $this->assertSame(404, $page->status());
        $this->assertStringContainsString('Not found', $page->content());
        $this->assertStringNotContainsString('No hot water in room 204', $page->content());
    }
}
