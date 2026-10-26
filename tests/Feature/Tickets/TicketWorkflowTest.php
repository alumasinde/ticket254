<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Core\Response;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketWorkflowTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    /**
     * @param array<string, mixed> $data
     */
    private function postJson(string $uri, array $data): Response
    {
        return $this->post($uri, $data, [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
    }

    public function test_assignment_sets_the_team_and_assignee_then_moves_open_to_assigned(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $response = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'team_id' => $teamId,
            'assigned_user_id' => $agent['user_id'],
        ]);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame(2, $data['version']);
        $this->assertSame('assigned', $data['status']['code']);
        $this->assertSame('IT', $data['team']['name']);
        $this->assertSame('Brian Kiptoo', $data['assigned_user']['name']);
        $this->assertSame(
            ['Ticket created', 'Assigned to Brian Kiptoo · IT', 'Status changed to Assigned'],
            array_column($data['events'], 'summary'),
        );

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame($teamId, (int) $row['team_id']);
        $this->assertSame($agent['user_id'], (int) $row['assigned_user_id']);
        $this->assertSame(2, (int) $row['version']);
    }

    public function test_an_agent_can_claim_a_team_ticket_but_cannot_assign_others_or_move_the_team(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');
        $ticket = $this->createTicketViaApi(['team_id' => $teamId]);
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($fixture['tenant_id'], $teamId, $agent['user_id']);
        $this->loginAs($agent);

        $toSomeoneElse = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'assigned_user_id' => $fixture['user_id'],
        ]);
        $this->assertSame(403, $toSomeoneElse->status());
        $this->assertSame('forbidden', $this->jsonBody($toSomeoneElse)['error']['code']);

        $otherTeam = $this->teamId($fixture['tenant_id'], 'Housekeeping');
        $teamChange = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'team_id' => $otherTeam,
        ]);
        $this->assertSame(403, $teamChange->status());

        $this->assertSame(1, (int) $this->ticketRow($fixture['tenant_id'], 1)['version']);

        $claim = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'assigned_user_id' => $agent['user_id'],
        ]);

        $this->assertSame(200, $claim->status(), $claim->content());
        $data = $this->jsonBody($claim)['data'];
        $this->assertSame('Brian Kiptoo', $data['assigned_user']['name']);
        $this->assertSame('IT', $data['team']['name']);
        $this->assertSame('assigned', $data['status']['code']);
        $this->assertSame(2, $data['version']);

        $note = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/messages', [
            'visibility' => 'internal',
            'body' => 'Checking the router logs now.',
        ]);
        $this->assertSame(201, $note->status(), $note->content());

        $move = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 2,
            'status' => 'in_progress',
        ]);
        $this->assertSame(200, $move->status(), $move->content());
        $this->assertSame('in_progress', $this->jsonBody($move)['data']['status']['code']);

        $priority = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/priority', [
            'version' => 3,
            'priority' => 'high',
        ]);
        $this->assertSame(403, $priority->status());
    }

    public function test_invalid_transitions_are_rejected_with_the_allowed_statuses(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();

        $response = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 1,
            'status' => 'resolved',
        ]);

        $this->assertSame(409, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('invalid_transition', $body['error']['code']);
        $this->assertSame('Cannot move from open to resolved.', $body['error']['message']);
        $this->assertSame(['assigned', 'in_progress', 'pending', 'closed'], $body['error']['allowed']);
        $this->assertSame(1, (int) $this->ticketRow($fixture['tenant_id'], 1)['version']);

        $unknown = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 1,
            'status' => 'archived',
        ]);
        $this->assertSame(422, $unknown->status());
        $this->assertArrayHasKey('status', $this->jsonBody($unknown)['error']['fields']);
    }

    public function test_resolved_and_closed_timestamps_follow_the_lifecycle(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $uri = '/api/v1/tickets/' . $ticket['reference'] . '/status';

        $started = $this->postJson($uri, ['version' => 1, 'status' => 'in_progress']);
        $this->assertSame(200, $started->status(), $started->content());
        $startedData = $this->jsonBody($started)['data'];
        $this->assertNull($startedData['resolved_at']);
        $this->assertNull($startedData['closed_at']);

        $resolved = $this->postJson($uri, ['version' => 2, 'status' => 'resolved']);
        $this->assertSame(200, $resolved->status(), $resolved->content());
        $resolvedData = $this->jsonBody($resolved)['data'];
        $this->assertSame('resolved', $resolvedData['status']['code']);
        $this->assertNotNull($resolvedData['resolved_at']);
        $this->assertNull($resolvedData['closed_at']);

        $closed = $this->postJson($uri, ['version' => 3, 'status' => 'closed']);
        $this->assertSame(200, $closed->status(), $closed->content());
        $closedData = $this->jsonBody($closed)['data'];
        $this->assertSame('closed', $closedData['status']['code']);
        $this->assertSame($resolvedData['resolved_at'], $closedData['resolved_at']);
        $this->assertNotNull($closedData['closed_at']);

        $reopened = $this->postJson($uri, ['version' => 4, 'status' => 'in_progress']);
        $this->assertSame(200, $reopened->status(), $reopened->content());
        $reopenedData = $this->jsonBody($reopened)['data'];
        $this->assertNull($reopenedData['resolved_at']);
        $this->assertNull($reopenedData['closed_at']);
        $this->assertSame(5, $reopenedData['version']);
    }

    public function test_a_transition_note_is_saved_as_an_internal_message(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();

        $response = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 1,
            'status' => 'in_progress',
            'note' => 'Router rebooted, monitoring for 30 minutes.',
        ]);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertCount(1, $data['messages']);
        $this->assertSame('internal', $data['messages'][0]['visibility']);
        $this->assertSame('Amina Otieno', $data['messages'][0]['author']['name']);
        $this->assertSame('Router rebooted, monitoring for 30 minutes.', $data['messages'][0]['body']);
        $this->assertNull($data['first_response_at']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertNull($row['first_response_at']);

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'ticket_reply')
            ->count();
        $this->assertSame(0, $outbox);
    }

    public function test_priority_changes_recalculate_due_and_honour_the_workspace_timezone(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $uri = '/api/v1/tickets/' . $ticket['reference'] . '/priority';

        $urgent = $this->postJson($uri, ['version' => 1, 'priority' => 'urgent']);
        $this->assertSame(200, $urgent->status(), $urgent->content());
        $data = $this->jsonBody($urgent)['data'];
        $this->assertSame('urgent', $data['priority']['code']);
        $this->assertSame(2, $data['version']);

        $due = strtotime((string) $this->ticketRow($fixture['tenant_id'], 1)['due_at'] . ' UTC');
        $this->assertNotFalse($due);
        $this->assertLessThanOrEqual(120, abs($due - (time() + 4 * 3600)));

        $summaries = array_column($data['events'], 'summary');
        $this->assertSame(['Priority changed to Urgent', 'Due date changed'], array_slice($summaries, -2));

        $scheduled = $this->postJson($uri, [
            'version' => 2,
            'priority' => 'high',
            'due_at' => '2026-12-01T09:30',
        ]);
        $this->assertSame(200, $scheduled->status(), $scheduled->content());
        $scheduledData = $this->jsonBody($scheduled)['data'];
        $this->assertSame('2026-12-01T06:30:00Z', $scheduledData['due_at']);
        $this->assertSame('2026-12-01 06:30:00', $this->ticketRow($fixture['tenant_id'], 1)['due_at']);

        $invalid = $this->postJson($uri, ['version' => 3, 'priority' => 'critical']);
        $this->assertSame(422, $invalid->status());
    }

    public function test_a_public_reply_sets_first_response_once_and_queues_an_email(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $uri = '/api/v1/tickets/' . $ticket['reference'] . '/messages';

        $reply = $this->postJson($uri, [
            'visibility' => 'public',
            'body' => 'An engineer has been dispatched to room 204.',
        ]);

        $this->assertSame(201, $reply->status(), $reply->content());
        $message = $this->jsonBody($reply)['data'];
        $this->assertSame('public', $message['visibility']);
        $this->assertSame('staff', $message['source']);
        $this->assertSame('Amina Otieno', $message['author']['name']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertNotNull($row['first_response_at']);
        $this->assertSame(1, (int) $row['version']);

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'ticket_reply')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame('njeri.wambui@example.co.ke', $outbox['recipient_email']);
        $this->assertSame('pending', $outbox['status']);
        $payload = json_decode((string) $outbox['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($ticket['reference'], $payload['reference']);
        $this->assertSame('An engineer has been dispatched to room 204.', $payload['body']);
        $this->assertSame('Glee Hotel', $payload['workspace_name']);

        $this->db()->table('tickets')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_number', 1)
            ->update(['first_response_at' => '2026-01-01 00:00:00']);

        $second = $this->postJson($uri, [
            'visibility' => 'public',
            'body' => 'Following up on the repair.',
        ]);
        $this->assertSame(201, $second->status(), $second->content());

        $after = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertSame('2026-01-01 00:00:00', $after['first_response_at']);

        $outboxCount = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'ticket_reply')
            ->count();
        $this->assertSame(2, $outboxCount);

        $detail = $this->get('/api/v1/tickets/' . $ticket['reference'], ['Accept' => 'application/json']);
        $messages = $this->jsonBody($detail)['data']['messages'];
        $this->assertCount(2, $messages);
        $this->assertSame('2026-01-01T00:00:00Z', $this->jsonBody($detail)['data']['first_response_at']);
        $this->assertStringContainsString('room 204', $messages[0]['body']);
        $this->assertStringContainsString('Following up', $messages[1]['body']);
    }

    public function test_an_internal_note_stays_staff_only_and_leaves_first_response_empty(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();

        $response = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/messages', [
            'visibility' => 'internal',
            'body' => 'Guest is on the loyalty programme, treat as priority.',
        ]);

        $this->assertSame(201, $response->status(), $response->content());
        $this->assertSame('internal', $this->jsonBody($response)['data']['visibility']);

        $row = $this->ticketRow($fixture['tenant_id'], 1);
        $this->assertNull($row['first_response_at']);
        $this->assertSame(1, (int) $row['version']);

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'ticket_reply')
            ->count();
        $this->assertSame(0, $outbox);

        $page = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Internal note', $page->content());
        $this->assertStringContainsString('Guest is on the loyalty programme, treat as priority.', $page->content());
    }

    public function test_stale_versions_are_rejected_for_assignment_and_status_changes(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $assigned = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'team_id' => $teamId,
        ]);
        $this->assertSame(200, $assigned->status(), $assigned->content());
        $this->assertSame(2, $this->jsonBody($assigned)['data']['version']);

        $staleAssign = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'assigned_user_id' => $agent['user_id'],
        ]);
        $this->assertSame(409, $staleAssign->status());
        $this->assertSame(2, $this->jsonBody($staleAssign)['error']['current_version']);

        $staleStatus = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 1,
            'status' => 'in_progress',
        ]);
        $this->assertSame(409, $staleStatus->status());
        $this->assertSame(2, $this->jsonBody($staleStatus)['error']['current_version']);

        $moved = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/status', [
            'version' => 2,
            'status' => 'in_progress',
        ]);
        $this->assertSame(200, $moved->status(), $moved->content());

        $stalePriority = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/priority', [
            'version' => 2,
            'priority' => 'high',
        ]);
        $this->assertSame(409, $stalePriority->status());
        $this->assertSame(3, $this->jsonBody($stalePriority)['error']['current_version']);
    }

    public function test_work_actions_cannot_cross_workspace_boundaries(): void
    {
        $first = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();

        $this->registerWorkspaceFixture('Savannah Lodge');

        $assign = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/assign', [
            'version' => 1,
            'assigned_user_id' => $first['user_id'],
        ]);
        $this->assertSame(404, $assign->status());
        $this->assertSame('not_found', $this->jsonBody($assign)['error']['code']);

        $message = $this->postJson('/api/v1/tickets/' . $ticket['reference'] . '/messages', [
            'visibility' => 'public',
            'body' => 'Cross-workspace reply attempt.',
        ]);
        $this->assertSame(404, $message->status());

        $page = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(404, $page->status());

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $first['tenant_id'])
            ->where('event_type', 'ticket_reply')
            ->count();
        $this->assertSame(0, $outbox);
    }

    public function test_the_ticket_page_runs_assignment_replies_and_transition_errors(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $ticket = $this->createTicketViaApi();
        $reference = $ticket['reference'];
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $page = $this->get('/app/tickets/' . $reference);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Save assignment', $page->content());
        $this->assertStringContainsString('Update status', $page->content());
        $this->assertStringContainsString('Update priority', $page->content());
        $this->assertStringContainsString('Post message', $page->content());
        $this->assertStringContainsString('First reply', $page->content());
        $this->assertStringContainsString('Not yet', $page->content());

        $assigned = $this->post('/app/tickets/' . $reference . '/assign', [
            '_token' => csrf_token(),
            'version' => '1',
            'team_id' => (string) $teamId,
            'assigned_user_id' => (string) $agent['user_id'],
        ]);
        $this->assertSame(302, $assigned->status());

        $afterAssign = $this->get('/app/tickets/' . $reference);
        $this->assertStringContainsString('Assignment updated.', $afterAssign->content());
        $this->assertStringContainsString('Brian Kiptoo', $afterAssign->content());
        $this->assertStringContainsString('name="version" value="2"', $afterAssign->content());

        $reply = $this->post('/app/tickets/' . $reference . '/messages', [
            '_token' => csrf_token(),
            'visibility' => 'public',
            'body' => 'Engineer is on the way to room 204.',
        ]);
        $this->assertSame(302, $reply->status());

        $afterReply = $this->get('/app/tickets/' . $reference);
        $this->assertStringContainsString('Reply sent.', $afterReply->content());
        $this->assertStringContainsString('Engineer is on the way to room 204.', $afterReply->content());
        $this->assertNotNull($this->ticketRow($fixture['tenant_id'], 1)['first_response_at']);

        $invalid = $this->post('/app/tickets/' . $reference . '/status', [
            '_token' => csrf_token(),
            'version' => '2',
            'status' => 'closed',
        ]);
        $this->assertSame(302, $invalid->status());

        $afterInvalid = $this->get('/app/tickets/' . $reference);
        $this->assertStringContainsString('Cannot move from assigned to closed.', $afterInvalid->content());

        $detail = $this->get('/api/v1/tickets/' . $reference, ['Accept' => 'application/json']);
        $this->assertSame('assigned', $this->jsonBody($detail)['data']['status']['code']);
    }

    public function test_an_agent_sees_a_limited_work_loop_on_the_ticket_page(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');
        $ticket = $this->createTicketViaApi(['team_id' => $teamId]);
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($fixture['tenant_id'], $teamId, $agent['user_id']);
        $this->loginAs($agent);

        $page = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString('Assign to me', $content);
        $this->assertStringContainsString('Update status', $content);
        $this->assertStringContainsString('Post message', $content);
        $this->assertStringNotContainsString('Save assignment', $content);
        $this->assertStringNotContainsString('Update priority', $content);
        $this->assertStringNotContainsString('Edit ticket', $content);

        $claim = $this->post('/app/tickets/' . $ticket['reference'] . '/assign', [
            '_token' => csrf_token(),
            'version' => '1',
            'assigned_user_id' => (string) $agent['user_id'],
        ]);
        $this->assertSame(302, $claim->status());

        $after = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertStringContainsString('Assignment updated.', $after->content());
        $this->assertStringNotContainsString('Assign to me', $after->content());
        $this->assertSame(2, (int) $this->ticketRow($fixture['tenant_id'], 1)['version']);
    }
}
