<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class CreateTicketTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_api_creation_allocates_a_number_and_records_event_and_outbox(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $categoryId = $this->categoryId($fixture['tenant_id'], 'Wi-Fi/Network');

        $ticket = $this->createTicketViaApi([
            'category_id' => $categoryId,
            'requester_email' => 'Njeri.Wambui@Example.CO.KE',
        ]);

        $this->assertSame('HD-000001', $ticket['reference']);
        $this->assertSame(1, $ticket['version']);
        $this->assertSame('open', $ticket['status']['code']);
        $this->assertSame('normal', $ticket['priority']['code']);
        $this->assertSame('IT', $ticket['team']['name']);
        $this->assertSame('Wi-Fi/Network', $ticket['category']['name']);
        $this->assertNull($ticket['assigned_user']);
        $this->assertSame('njeri.wambui@example.co.ke', $ticket['requester']['email']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);

        $this->assertSame('staff', $row['source']);
        $this->assertSame(1, (int) $row['version']);
        $this->assertSame('njeri.wambui@example.co.ke', $row['requester_email']);
        $this->assertSame($categoryId, (int) $row['category_id']);
        $this->assertNotNull($row['due_at']);

        $due = strtotime((string) $row['due_at'] . ' UTC');
        $this->assertNotFalse($due);
        $this->assertEqualsWithDelta(time() + (24 * 3600), $due, 120);

        $counter = $this->db()->table('ticket_counters')->where('tenant_id', $fixture['tenant_id'])->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter['last_number']);

        $event = $this->db()->table('ticket_events')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_id', (int) $row['id'])
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('created', $event['event_type']);
        $this->assertSame($fixture['user_id'], (int) $event['actor_user_id']);
        $this->assertNull($event['old_value']);

        $newValue = json_decode((string) $event['new_value'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('HD-000001', $newValue['reference']);
        $this->assertSame('open', $newValue['status']);
        $this->assertSame('normal', $newValue['priority']);
        $this->assertSame($categoryId, $newValue['category_id']);

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_id', (int) $row['id'])
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame('email', $outbox['channel']);
        $this->assertSame('ticket_created', $outbox['event_type']);
        $this->assertSame('pending', $outbox['status']);
        $this->assertSame('njeri.wambui@example.co.ke', $outbox['recipient_email']);
        $this->assertSame('Njeri Wambui', $outbox['recipient_name']);

        $payload = json_decode((string) $outbox['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('HD-000001', $payload['reference']);
        $this->assertSame('Glee Hotel', $payload['workspace_name']);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_category_routes_the_team_and_supplies_the_default_priority(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $categoryId = $this->categoryId($fixture['tenant_id'], 'Room maintenance');
        $high = $this->db()->table('ticket_priorities')->where('code', 'high')->first();
        $this->assertNotNull($high);

        $this->db()->table('categories')->where('id', $categoryId)->update([
            'default_priority_id' => (int) $high['id'],
        ]);

        $ticket = $this->createTicketViaApi(['category_id' => $categoryId]);

        $this->assertSame('Engineering', $ticket['team']['name']);
        $this->assertSame('high', $ticket['priority']['code']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $due = strtotime((string) $row['due_at'] . ' UTC');
        $this->assertNotFalse($due);
        $this->assertEqualsWithDelta(time() + (8 * 3600), $due, 120);
    }

    public function test_an_explicit_team_and_priority_override_the_category(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $categoryId = $this->categoryId($fixture['tenant_id'], 'Wi-Fi/Network');
        $housekeeping = $this->teamId($fixture['tenant_id'], 'Housekeeping');

        $ticket = $this->createTicketViaApi([
            'category_id' => $categoryId,
            'team_id' => $housekeeping,
            'priority' => 'urgent',
        ]);

        $this->assertSame('Housekeeping', $ticket['team']['name']);
        $this->assertSame('urgent', $ticket['priority']['code']);
    }

    public function test_an_explicit_due_date_is_converted_from_the_workspace_timezone(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $ticket = $this->createTicketViaApi(['due_at' => '2026-11-02T09:30']);

        $this->assertSame('2026-11-02T06:30:00Z', $ticket['due_at']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame('2026-11-02 06:30:00', $row['due_at']);
    }

    public function test_site_must_belong_to_the_workspace_and_is_recorded(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $siteId = $this->db()->table('sites')->insert([
            'tenant_id' => $fixture['tenant_id'],
            'name' => 'Glee Westlands',
        ]);

        $ticket = $this->createTicketViaApi(['site_id' => $siteId]);

        $this->assertSame('Glee Westlands', $ticket['site']['name']);

        $other = $this->registerWorkspaceFixture('Savannah Lodge');
        $foreignSite = $this->db()->table('sites')->insert([
            'tenant_id' => $other['tenant_id'],
            'name' => 'Savannah Annex',
        ]);

        $this->loginAs($fixture);
        $response = $this->post('/api/v1/tickets', $this->ticketPayload(['site_id' => $foreignSite]), [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            'The selected site is not available in this workspace.',
            $this->jsonBody($response)['error']['fields']['site_id'],
        );
    }

    public function test_validation_errors_do_not_consume_a_number_or_write_anything(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->post('/api/v1/tickets', [
            'requester_name' => '',
            'requester_email' => 'not-an-email',
            'subject' => '',
            'description' => '',
        ], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);

        $this->assertSame(422, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertArrayHasKey('requester_name', $body['error']['fields']);
        $this->assertArrayHasKey('requester_email', $body['error']['fields']);
        $this->assertArrayHasKey('subject', $body['error']['fields']);
        $this->assertArrayHasKey('description', $body['error']['fields']);

        $counter = $this->db()->table('ticket_counters')->where('tenant_id', $fixture['tenant_id'])->first();
        $this->assertNotNull($counter);
        $this->assertSame(0, (int) $counter['last_number']);

        $this->assertSame(0, $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->count());
        $this->assertSame(0, $this->db()->table('ticket_events')->where('tenant_id', $fixture['tenant_id'])->count());
        $this->assertSame(0, $this->db()->table('notification_outbox')->where('tenant_id', $fixture['tenant_id'])->count());
    }

    public function test_a_foreign_category_is_rejected_without_leaking_it(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');
        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $foreignCategory = $this->categoryId($savannah['tenant_id'], 'Wi-Fi/Network');
        $foreignTeam = $this->teamId($savannah['tenant_id'], 'IT');

        $this->loginAs($glee);

        foreach (['category_id' => $foreignCategory, 'team_id' => $foreignTeam] as $field => $value) {
            $response = $this->post('/api/v1/tickets', $this->ticketPayload([$field => $value]), [
                'Accept' => 'application/json',
                'X-CSRF-Token' => csrf_token(),
            ]);

            $this->assertSame(422, $response->status());
            $this->assertArrayHasKey($field, $this->jsonBody($response)['error']['fields']);
        }

        $this->assertSame(0, $this->db()->table('tickets')->where('tenant_id', $glee['tenant_id'])->count());
        $this->assertSame(0, $this->db()->table('tickets')->where('tenant_id', $savannah['tenant_id'])->count());
    }

    public function test_each_workspace_numbers_its_own_tickets(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');

        $first = $this->createTicketViaApi();
        $second = $this->createTicketViaApi(['subject' => 'Lobby printer jam']);
        $this->assertSame('HD-000001', $first['reference']);
        $this->assertSame('HD-000002', $second['reference']);

        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $third = $this->createTicketViaApi(['subject' => 'Pool towels missing']);
        $this->assertSame('HD-000001', $third['reference']);
        $this->assertNotSame($glee['tenant_id'], $savannah['tenant_id']);

        $gleeRows = $this->db()->select(
            'SELECT ticket_number FROM tickets WHERE tenant_id = ? ORDER BY ticket_number',
            [$glee['tenant_id']],
        );
        $this->assertSame([1, 2], array_map(static fn (array $row): int => (int) $row['ticket_number'], $gleeRows));
    }
}
