<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_dashboard_reports_real_queue_health_and_needs_attention_reasons(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $waiting = $this->createTicketViaApi(['subject' => 'Lobby air conditioning is down']);
        $resolved = $this->createTicketViaApi(['subject' => 'Room 12 key card needs reset']);

        $waitingRow = $this->ticketRow($fixture['tenant_id'], 1);
        $resolvedRow = $this->ticketRow($fixture['tenant_id'], 2);

        $this->db()->table('tickets')->where('id', (int) $waitingRow['id'])->update([
            'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * 3600),
            'due_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            'assigned_user_id' => null,
            'first_response_at' => null,
        ]);

        $resolvedStatus = (int) $this->db()->table('ticket_statuses')->where('code', 'resolved')->value('id');

        $this->db()->table('tickets')->where('id', (int) $resolvedRow['id'])->update([
            'status_id' => $resolvedStatus,
            'assigned_user_id' => $fixture['user_id'],
            'created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 3600),
            'first_response_at' => gmdate('Y-m-d H:i:s', time() - 2 * 3600),
            'resolved_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $response = $this->get('/api/v1/dashboard', ['Accept' => 'application/json']);
        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame(1, $data['counts']['open']);
        $this->assertSame(1, $data['counts']['unassigned']);
        $this->assertSame(1, $data['counts']['overdue']);
        $this->assertSame(1, $data['counts']['active']);
        $this->assertGreaterThanOrEqual(1, $data['resolved_today']);
        $this->assertEqualsWithDelta(3.0, $data['avg_resolution_hours'], 0.1);
        $this->assertSame('7d', $data['period']['key']);

        $attention = array_values(array_filter(
            $data['needs_attention'],
            static fn (array $ticket): bool => $ticket['reference'] === $waiting['reference'],
        ));
        $this->assertCount(1, $attention);
        $this->assertSame(['overdue', 'unassigned', 'no_first_response'], $attention[0]['reasons']);

        $this->assertContains($resolved['reference'], array_column($data['recent'], 'reference'));

        $page = $this->get('/app');
        $this->assertSame(200, $page->status());
        $content = $page->content();
        $this->assertStringContainsString('Today at Glee Hotel', $content);
        $this->assertStringContainsString('Needs attention', $content);
        $this->assertStringContainsString('Resolved today', $content);
        $this->assertStringContainsString('Average resolution', $content);
        $this->assertStringContainsString($waiting['reference'], $content);
        $this->assertStringContainsString('No first reply', $content);
        $this->assertStringContainsString('3h', $content);
    }

    public function test_agents_only_see_counts_for_their_own_or_team_tickets(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $itTeam = $this->teamId($fixture['tenant_id'], 'IT');
        $frontOffice = $this->teamId($fixture['tenant_id'], 'Front Office');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($fixture['tenant_id'], $itTeam, $agent['user_id']);

        $mine = $this->createTicketViaApi(['subject' => 'Wi-Fi drops in the boardroom', 'team_id' => $itTeam]);
        $someoneElse = $this->createTicketViaApi(['subject' => 'Invoice printer jams']);
        $otherTeam = $this->createTicketViaApi(['subject' => 'Airport pickup is late', 'team_id' => $frontOffice]);

        $assigned = (int) $this->db()->table('ticket_statuses')->where('code', 'assigned')->value('id');

        $this->db()->table('tickets')->where('id', (int) $this->ticketRow($fixture['tenant_id'], 1)['id'])->update([
            'status_id' => $assigned,
            'assigned_user_id' => $agent['user_id'],
            'first_response_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db()->table('tickets')->where('id', (int) $this->ticketRow($fixture['tenant_id'], 2)['id'])->update([
            'status_id' => $assigned,
            'assigned_user_id' => $fixture['user_id'],
            'first_response_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $ownerView = $this->jsonBody($this->get('/api/v1/dashboard', ['Accept' => 'application/json']))['data'];
        $this->assertSame(3, $ownerView['counts']['active']);

        $this->loginAs($agent);

        $agentView = $this->jsonBody($this->get('/api/v1/dashboard', ['Accept' => 'application/json']))['data'];

        $this->assertSame(1, $agentView['counts']['active']);
        $this->assertSame(1, $agentView['counts']['assigned']);
        $this->assertSame(0, $agentView['counts']['unassigned']);
        $this->assertSame([$mine['reference']], array_column($agentView['recent'], 'reference'));

        $encoded = json_encode($agentView, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($someoneElse['reference'], $encoded);
        $this->assertStringNotContainsString($otherTeam['reference'], $encoded);
    }
}
