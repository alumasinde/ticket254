<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TrackingTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_tracking_hides_internal_notes_and_shows_public_replies(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug'], ['subject' => 'Broken lift']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        $note = $this->apiPost('/api/v1/tickets/' . $created['reference'] . '/messages', [
            'visibility' => 'internal',
            'body' => 'Internal: spare part ordered from Nairobi.',
        ]);
        $this->assertSame(201, $note->status(), $note->content());

        $reply = $this->apiPost('/api/v1/tickets/' . $created['reference'] . '/messages', [
            'visibility' => 'public',
            'body' => 'An engineer will visit before noon.',
        ]);
        $this->assertSame(201, $reply->status(), $reply->content());

        $track = $this->trackViaApi($token);
        $this->assertSame(200, $track->status(), $track->content());

        $data = $this->jsonBody($track)['data'];
        $this->assertCount(1, $data['messages']);
        $this->assertSame('Support team', $data['messages'][0]['author']);
        $this->assertSame('An engineer will visit before noon.', $data['messages'][0]['body']);
        $this->assertFalse($data['messages'][0]['mine']);
        $this->assertStringNotContainsString('spare part ordered', $track->content());

        $page = $this->trackingPage($token);
        $this->assertSame(200, $page->status(), $page->content());
        $this->assertStringContainsString('An engineer will visit before noon.', $page->content());
        $this->assertStringNotContainsString('spare part ordered', $page->content());

        $this->assertSame(200, $this->trackViaApi($token)->status(), 'The original tracking link stays valid.');

        $outbox = $this->db()->selectOne(
            'SELECT * FROM notification_outbox WHERE tenant_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1',
            [$fixture['tenant_id'], 'ticket_reply'],
        );

        $this->assertNotNull($outbox);
        $this->assertSame('njeri.wambui@example.co.ke', $outbox['recipient_email']);

        $payload = json_decode((string) $outbox['payload'], true, 512, JSON_THROW_ON_ERROR);
        $freshToken = $this->tokenFromUrl((string) $payload['tracking_url']);
        $this->assertNotSame($token, $freshToken);
        $this->assertSame(200, $this->trackViaApi($freshToken)->status());
    }

    public function test_a_requester_reply_reopens_pending_and_notifies_the_assignee(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $created = $this->createPublicRequest($fixture['slug'], ['subject' => 'Air conditioning']);
        $token = $this->tokenFromUrl($created['tracking_url']);
        $reference = $created['reference'];

        $assign = $this->apiPost('/api/v1/tickets/' . $reference . '/assign', [
            'version' => 1,
            'assigned_user_id' => $agent['user_id'],
        ]);
        $this->assertSame(200, $assign->status(), $assign->content());

        $pending = $this->apiPost('/api/v1/tickets/' . $reference . '/status', [
            'version' => 2,
            'status' => 'pending',
        ]);
        $this->assertSame(200, $pending->status(), $pending->content());

        $reply = $this->replyViaApi($token, 'The unit still makes a loud noise at night.');

        $this->assertSame(201, $reply->status(), $reply->content());
        $message = $this->jsonBody($reply)['data'];
        $this->assertSame('You', $message['author']);
        $this->assertTrue($message['mine']);
        $this->assertSame('The unit still makes a loud noise at night.', $message['body']);

        $this->assertSame('in_progress', $this->ticketStatusCode($fixture['tenant_id'], 1));

        $detail = $this->jsonBody($this->apiGet('/api/v1/tickets/' . $reference))['data'];
        $last = $detail['messages'][count($detail['messages']) - 1];
        $this->assertSame('requester', $last['source']);
        $this->assertSame('public', $last['visibility']);

        $eventTypes = array_column(
            $this->db()->select(
                'SELECT event_type FROM ticket_events WHERE tenant_id = ? AND ticket_id = ? ORDER BY id ASC',
                [$fixture['tenant_id'], (int) $this->ticketRow($fixture['tenant_id'], 1)['id']],
            ),
            'event_type',
        );

        $this->assertContains('requester_replied', $eventTypes);
        $this->assertContains('status_changed', $eventTypes);

        $outbox = $this->db()->selectOne(
            'SELECT * FROM notification_outbox WHERE tenant_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1',
            [$fixture['tenant_id'], 'ticket_requester_reply'],
        );

        $this->assertNotNull($outbox);
        $this->assertSame($agent['email'], $outbox['recipient_email']);
        $this->assertStringContainsString('HD-000001', (string) $outbox['payload']);
        $this->assertStringContainsString('/app/tickets/HD-000001', (string) $outbox['payload']);
    }

    public function test_a_closed_ticket_does_not_accept_replies(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        $closed = $this->apiPost('/api/v1/tickets/' . $created['reference'] . '/status', [
            'version' => 1,
            'status' => 'closed',
        ]);
        $this->assertSame(200, $closed->status(), $closed->content());

        $track = $this->jsonBody($this->trackViaApi($token))['data'];
        $this->assertFalse($track['can_reply']);

        $reply = $this->replyViaApi($token, 'One more thing.');
        $this->assertSame(422, $reply->status(), $reply->content());
        $this->assertSame('validation_failed', $this->jsonBody($reply)['error']['code']);
        $this->assertArrayHasKey('body', $this->jsonBody($reply)['error']['fields']);

        $page = $this->trackingPage($token);
        $this->assertStringContainsString('Replies are disabled', $page->content());
    }

    public function test_replies_can_be_switched_off_for_the_workspace(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->apiPatch('/api/v1/settings/public-form', ['allow_requester_replies' => 0]);

        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        $track = $this->jsonBody($this->trackViaApi($token))['data'];
        $this->assertFalse($track['can_reply']);

        $reply = $this->replyViaApi($token, 'Is anyone there?');
        $this->assertSame(422, $reply->status(), $reply->content());
    }

    public function test_regenerating_a_tracking_link_revokes_the_old_token(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $old = $this->tokenFromUrl($created['tracking_url']);

        $regenerated = $this->apiPost('/api/v1/tickets/' . $created['reference'] . '/tracking-token/regenerate');
        $this->assertSame(200, $regenerated->status(), $regenerated->content());

        $newUrl = (string) $this->jsonBody($regenerated)['data']['tracking_url'];
        $new = $this->tokenFromUrl($newUrl);

        $this->assertNotSame($old, $new);
        $this->assertSame(404, $this->trackViaApi($old)->status());
        $this->assertSame(200, $this->trackViaApi($new)->status());
    }

    public function test_expired_revoked_and_unknown_tokens_are_not_found(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        $this->assertSame(404, $this->trackViaApi(str_repeat('a', 64))->status());

        $this->db()->statement(
            'UPDATE ticket_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE tenant_id = ?',
            [$fixture['tenant_id']],
        );

        $this->assertSame(404, $this->trackViaApi($token)->status());

        $expired = $this->trackViaApi($token);
        $this->assertSame('not_found', $this->jsonBody($expired)['error']['code']);

        $second = $this->createPublicRequest($fixture['slug']);
        $secondToken = $this->tokenFromUrl($second['tracking_url']);

        $this->db()->statement(
            'UPDATE ticket_tokens SET revoked_at = UTC_TIMESTAMP() WHERE tenant_id = ?',
            [$fixture['tenant_id']],
        );

        $this->assertSame(404, $this->trackViaApi($secondToken)->status());
    }

    public function test_tracking_lookups_are_rate_limited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(200, $this->trackViaApi($token)->status(), 'Lookup ' . $i);
        }

        $limited = $this->trackViaApi($token);

        $this->assertSame(429, $limited->status(), $limited->content());
        $this->assertSame('rate_limited', $this->jsonBody($limited)['error']['code']);
        $this->assertNotNull($limited->header('Retry-After'));
    }

    public function test_requester_replies_are_rate_limited_per_token(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        for ($i = 1; $i <= 10; $i++) {
            $reply = $this->replyViaApi($token, 'Update ' . $i);
            $this->assertSame(201, $reply->status(), $reply->content());
        }

        $limited = $this->replyViaApi($token, 'One too many');

        $this->assertSame(429, $limited->status(), $limited->content());
        $this->assertSame('rate_limited', $this->jsonBody($limited)['error']['code']);
    }

    public function test_suspended_workspace_pages_are_unavailable(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $created = $this->createPublicRequest($fixture['slug']);
        $token = $this->tokenFromUrl($created['tracking_url']);

        $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->update(['status' => 'suspended']);

        $api = $this->trackViaApi($token);
        $this->assertSame(403, $api->status(), $api->content());
        $this->assertSame('tenant_suspended', $this->jsonBody($api)['error']['code']);

        $page = $this->trackingPage($token);
        $this->assertSame(403, $page->status(), $page->content());
        $this->assertStringContainsString('This request form is unavailable', $page->content());
        $this->assertStringContainsString('Glee Hotel', $page->content());
    }

    private function ticketStatusCode(int $tenantId, int $number): string
    {
        $row = $this->db()->selectOne(
            'SELECT st.code FROM tickets t INNER JOIN ticket_statuses st ON st.id = t.status_id '
            . 'WHERE t.tenant_id = ? AND t.ticket_number = ?',
            [$tenantId, $number],
        );

        $this->assertNotNull($row);

        return (string) $row['code'];
    }
}
