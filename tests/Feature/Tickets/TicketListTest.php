<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketListTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function seededWorkspace(): array
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $it = $this->categoryId($fixture['tenant_id'], 'Wi-Fi/Network');
        $housekeeping = $this->categoryId($fixture['tenant_id'], 'Housekeeping');

        $first = $this->createTicketViaApi([
            'category_id' => $it,
            'subject' => 'Wi-Fi drops in the lobby',
            'requester_name' => 'Njeri Wambui',
            'requester_email' => 'njeri.wambui@example.co.ke',
        ]);

        $second = $this->createTicketViaApi([
            'category_id' => $housekeeping,
            'subject' => 'Towels missing from room 12',
            'requester_name' => 'Peter Mwangi',
            'requester_email' => 'peter.mwangi@example.co.ke',
        ]);

        $third = $this->createTicketViaApi([
            'subject' => 'Printer jam at reception',
            'requester_name' => 'Grace Achieng',
            'requester_email' => 'grace.achieng@example.co.ke',
        ]);

        return [$fixture, [$first, $second, $third]];
    }

    public function test_default_list_is_newest_first_with_summary_fields_only(): void
    {
        [, $tickets] = $this->seededWorkspace();

        $body = $this->listTicketsViaApi();

        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(25, $body['meta']['per_page']);
        $this->assertSame(
            [$tickets[2]['reference'], $tickets[1]['reference'], $tickets[0]['reference']],
            array_column($body['data'], 'reference'),
        );

        $row = $body['data'][0];
        $this->assertArrayHasKey('reference', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('priority', $row);
        $this->assertArrayHasKey('requester', $row);
        $this->assertArrayHasKey('team', $row);
        $this->assertArrayHasKey('is_overdue', $row);
        $this->assertArrayNotHasKey('description', $row);
        $this->assertArrayNotHasKey('version', $row);
    }

    public function test_status_and_team_filters_narrow_the_list(): void
    {
        [$fixture, $tickets] = $this->seededWorkspace();
        $it = $this->teamId($fixture['tenant_id'], 'IT');

        $byTeam = $this->listTicketsViaApi(['team_id' => $it]);
        $this->assertCount(1, $byTeam['data']);
        $this->assertSame($tickets[0]['reference'], $byTeam['data'][0]['reference']);

        $resolved = $this->db()->table('ticket_statuses')->where('code', 'resolved')->first();
        $this->assertNotNull($resolved);
        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 2)
            ->update(['status_id' => (int) $resolved['id']]);

        $byStatus = $this->listTicketsViaApi(['status' => 'resolved']);
        $this->assertCount(1, $byStatus['data']);
        $this->assertSame($tickets[1]['reference'], $byStatus['data'][0]['reference']);
        $this->assertSame('Resolved', $byStatus['data'][0]['status']['label']);

        $this->assertCount(2, $this->listTicketsViaApi(['status' => 'open'])['data']);
    }

    public function test_priority_and_assignee_filters(): void
    {
        [$fixture, $tickets] = $this->seededWorkspace();
        $urgent = $this->db()->table('ticket_priorities')->where('code', 'urgent')->first();
        $this->assertNotNull($urgent);

        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 1)
            ->update(['priority_id' => (int) $urgent['id'], 'assigned_user_id' => $fixture['user_id']]);

        $this->assertCount(1, $this->listTicketsViaApi(['priority' => 'urgent'])['data']);

        $mine = $this->listTicketsViaApi(['mine' => 1]);
        $this->assertCount(1, $mine['data']);
        $this->assertSame($tickets[0]['reference'], $mine['data'][0]['reference']);
        $this->assertSame('Amina Otieno', $mine['data'][0]['assigned_user']['name']);

        $unassigned = $this->listTicketsViaApi(['unassigned' => 1]);
        $this->assertSame(
            [$tickets[2]['reference'], $tickets[1]['reference']],
            array_column($unassigned['data'], 'reference'),
        );
    }

    public function test_overdue_filter_uses_due_date_and_ignores_terminal_tickets(): void
    {
        [$fixture, $tickets] = $this->seededWorkspace();

        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 1)
            ->update(['due_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        $overdue = $this->listTicketsViaApi(['overdue' => 1]);

        $this->assertCount(1, $overdue['data']);
        $this->assertSame($tickets[0]['reference'], $overdue['data'][0]['reference']);
        $this->assertTrue($overdue['data'][0]['is_overdue']);

        $closed = $this->db()->table('ticket_statuses')->where('code', 'closed')->first();
        $this->assertNotNull($closed);
        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 1)
            ->update(['status_id' => (int) $closed['id']]);

        $this->assertCount(0, $this->listTicketsViaApi(['overdue' => 1])['data']);
    }

    public function test_search_matches_reference_subject_and_requester(): void
    {
        [, $tickets] = $this->seededWorkspace();

        $byReference = $this->listTicketsViaApi(['q' => $tickets[1]['reference']]);
        $this->assertCount(1, $byReference['data']);
        $this->assertSame($tickets[1]['reference'], $byReference['data'][0]['reference']);

        $byNumber = $this->listTicketsViaApi(['q' => '3']);
        $this->assertCount(1, $byNumber['data']);
        $this->assertSame($tickets[2]['reference'], $byNumber['data'][0]['reference']);

        $bySubject = $this->listTicketsViaApi(['q' => 'lobby']);
        $this->assertCount(1, $bySubject['data']);
        $this->assertSame($tickets[0]['reference'], $bySubject['data'][0]['reference']);

        $byRequester = $this->listTicketsViaApi(['q' => 'peter.mwangi@example.co.ke']);
        $this->assertCount(1, $byRequester['data']);
        $this->assertSame($tickets[1]['reference'], $byRequester['data'][0]['reference']);
    }

    public function test_created_date_filters_use_the_workspace_timezone(): void
    {
        [$fixture, $tickets] = $this->seededWorkspace();

        $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->where('ticket_number', 1)
            ->update(['created_at' => '2026-10-25 20:30:00']);
        $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->where('ticket_number', 2)
            ->update(['created_at' => '2026-10-25 21:30:00']);
        $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->where('ticket_number', 3)
            ->update(['created_at' => '2026-10-26 10:00:00']);

        $from26 = $this->listTicketsViaApi(['created_from' => '2026-10-26']);
        $this->assertSame(
            [$tickets[2]['reference'], $tickets[1]['reference']],
            array_column($from26['data'], 'reference'),
        );

        $upTo25 = $this->listTicketsViaApi(['created_to' => '2026-10-25']);
        $this->assertSame([$tickets[0]['reference']], array_column($upTo25['data'], 'reference'));

        $both = $this->listTicketsViaApi(['created_from' => '2026-10-26', 'created_to' => '2026-10-26']);
        $this->assertSame(
            [$tickets[2]['reference'], $tickets[1]['reference']],
            array_column($both['data'], 'reference'),
        );
    }

    public function test_pagination_clamps_and_reports_pages(): void
    {
        [, $tickets] = $this->seededWorkspace();

        $pageOne = $this->listTicketsViaApi(['per_page' => 2, 'page' => 1]);
        $this->assertSame(3, $pageOne['meta']['total']);
        $this->assertSame(2, $pageOne['meta']['total_pages']);
        $this->assertSame(
            [$tickets[2]['reference'], $tickets[1]['reference']],
            array_column($pageOne['data'], 'reference'),
        );

        $pageTwo = $this->listTicketsViaApi(['per_page' => 2, 'page' => 2]);
        $this->assertSame([$tickets[0]['reference']], array_column($pageTwo['data'], 'reference'));

        $this->assertSame(100, $this->listTicketsViaApi(['per_page' => 500])['meta']['per_page']);
    }

    public function test_page_renders_the_shell_filters_and_ticket_rows(): void
    {
        [, $tickets] = $this->seededWorkspace();

        $page = $this->get('/app/tickets');

        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString('Tickets', $content);
        $this->assertStringContainsString('Wi-Fi drops in the lobby', $content);
        $this->assertStringContainsString($tickets[0]['reference'], $content);
        $this->assertStringContainsString('Housekeeping', $content);
        $this->assertStringContainsString('Apply', $content);
        $this->assertStringContainsString('Assigned to me', $content);
        $this->assertStringContainsString(route('tickets.create'), $content);

        $filtered = $this->get('/app/tickets?q=lobby');
        $this->assertSame(200, $filtered->status());
        $this->assertStringContainsString('Wi-Fi drops in the lobby', $filtered->content());
        $this->assertStringNotContainsString('Towels missing from room 12', $filtered->content());
    }

    public function test_page_pagination_links_keep_active_filters(): void
    {
        $this->seededWorkspace();

        $page = $this->get('/app/tickets?per_page=2&status=open');
        $this->assertSame(200, $page->status());

        $content = $page->content();
        $this->assertStringContainsString('Page 1 of 2', $content);
        $this->assertStringContainsString('per_page=2', $content);
        $this->assertStringContainsString('status=open', $content);
        $this->assertStringContainsString('page=2', $content);
    }

    public function test_page_shows_an_empty_state_for_an_unmatched_filter(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $page = $this->get('/app/tickets?q=nothing-matches-this');

        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('No tickets match this view', $page->content());
    }
}
