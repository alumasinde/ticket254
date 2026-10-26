<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class PublicRequestTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsWorkspaces;

    protected function tearDown(): void
    {
        $this->cleanupTempUploads();

        parent::tearDown();
    }

    public function test_public_configuration_exposes_the_workspace_choices(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->publicConfiguration($fixture['slug']);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame('Glee Hotel', $data['name']);
        $this->assertFalse($data['requires_work_email']);
        $this->assertNull($data['email_domain_hint']);
        $this->assertTrue($data['attachments_enabled']);
        $this->assertContains('Wi-Fi/Network', array_column($data['categories'], 'name'));
        $this->assertSame([], $data['sites']);
    }

    public function test_a_public_request_creates_a_ticket_token_and_outbox_row(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $result = $this->createPublicRequest($fixture['slug'], [
            'subject' => 'Cold water in room 204',
            'description' => 'No hot water since 06:00.',
        ]);

        $this->assertSame('HD-000001', $result['reference']);
        $token = $this->tokenFromUrl($result['tracking_url']);
        $this->assertSame(64, strlen($token));

        $ticket = $this->db()->selectOne(
            'SELECT t.*, st.code AS status_code, pr.code AS priority_code '
            . 'FROM tickets t '
            . 'INNER JOIN ticket_statuses st ON st.id = t.status_id '
            . 'INNER JOIN ticket_priorities pr ON pr.id = t.priority_id '
            . 'WHERE t.tenant_id = ? AND t.ticket_number = ?',
            [$fixture['tenant_id'], 1],
        );

        $this->assertNotNull($ticket);
        $this->assertSame('public', $ticket['source']);
        $this->assertNull($ticket['requester_user_id']);
        $this->assertSame('open', $ticket['status_code']);
        $this->assertSame('normal', $ticket['priority_code']);
        $this->assertSame('njeri.wambui@example.co.ke', $ticket['requester_email']);

        $stored = $this->db()->selectOne(
            'SELECT * FROM ticket_tokens WHERE tenant_id = ? AND ticket_id = ?',
            [$fixture['tenant_id'], (int) $ticket['id']],
        );

        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $token), $stored['token_hash']);
        $this->assertNotSame($token, $stored['token_hash']);

        $outbox = $this->db()->selectOne(
            'SELECT * FROM notification_outbox WHERE tenant_id = ? AND ticket_id = ? AND event_type = ?',
            [$fixture['tenant_id'], (int) $ticket['id'], 'ticket_created'],
        );

        $this->assertNotNull($outbox);
        $this->assertSame('njeri.wambui@example.co.ke', $outbox['recipient_email']);
        $this->assertSame('pending', $outbox['status']);
        $this->assertStringContainsString($result['tracking_url'], (string) $outbox['payload']);

        $track = $this->trackViaApi($token);

        $this->assertSame(200, $track->status(), $track->content());
        $data = $this->jsonBody($track)['data'];
        $this->assertSame('HD-000001', $data['reference']);
        $this->assertSame('Cold water in room 204', $data['subject']);
        $this->assertSame('open', $data['status']['code']);
        $this->assertSame([], $data['messages']);
        $this->assertTrue($data['can_reply']);
        $this->assertArrayNotHasKey('events', $data);
        $this->assertArrayNotHasKey('requester_email', $data);

        $page = $this->trackingPage($token);

        $this->assertSame(200, $page->status(), $page->content());
        $this->assertStringContainsString('Cold water in room 204', $page->content());
        $this->assertStringContainsString('HD-000001', $page->content());
    }

    public function test_public_requests_validate_input_and_reject_a_filled_honeypot(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $invalid = $this->submitPublicRequest($fixture['slug'], [
            'requester_name' => '',
            'requester_email' => 'not-an-email',
            'subject' => '',
            'description' => '',
        ]);

        $this->assertSame(422, $invalid->status(), $invalid->content());
        $body = $this->jsonBody($invalid);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame(
            ['requester_name', 'requester_email', 'subject', 'description'],
            array_keys($body['error']['fields']),
        );

        $honeypot = $this->submitPublicRequest($fixture['slug'], ['website' => 'https://spam.example']);

        $this->assertSame(422, $honeypot->status(), $honeypot->content());
        $this->assertSame('validation_failed', $this->jsonBody($honeypot)['error']['code']);
        $this->assertArrayHasKey('website', $this->jsonBody($honeypot)['error']['fields']);

        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_allowed_email_domains_restrict_public_requests(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $update = $this->apiPatch('/api/v1/settings/public-form', [
            'mode' => 'slug',
            'allowed_email_domains' => 'gleehotel.co.ke, @gleenairobi.com',
        ]);

        $this->assertSame(200, $update->status(), $update->content());

        $config = $this->jsonBody($this->publicConfiguration($fixture['slug']))['data'];
        $this->assertTrue($config['requires_work_email']);
        $this->assertSame('gleehotel.co.ke', $config['email_domain_hint']);

        $denied = $this->submitPublicRequest($fixture['slug'], [
            'requester_email' => 'guest@gmail.com',
        ]);

        $this->assertSame(422, $denied->status(), $denied->content());
        $error = $this->jsonBody($denied)['error'];
        $this->assertSame('email_domain_not_allowed', $error['code']);
        $this->assertArrayHasKey('requester_email', $error['fields']);

        $this->createPublicRequest($fixture['slug'], [
            'requester_email' => 'guest@staff.gleehotel.co.ke',
        ]);
        $this->createPublicRequest($fixture['slug'], [
            'requester_email' => 'guest@gleenairobi.com',
        ]);

        $this->assertSame(2, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_private_mode_only_accepts_the_random_key(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $update = $this->apiPatch('/api/v1/settings/public-form', ['mode' => 'private']);
        $this->assertSame(200, $update->status(), $update->content());

        $key = (string) $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->value('public_key');
        $this->assertNotSame('', $key);

        $this->assertSame(404, $this->publicConfiguration($fixture['slug'])->status());
        $this->assertSame(200, $this->publicConfiguration($key)->status());
        $this->assertSame(404, $this->publicFormPage($fixture['slug'])->status());
        $this->assertSame(200, $this->publicFormPage($key)->status());

        $this->createPublicRequest($key);

        $this->assertSame(1, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_a_disabled_public_form_is_not_found(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->apiPatch('/api/v1/settings/public-form', ['enabled' => 0]);

        $this->assertSame(404, $this->publicConfiguration($fixture['slug'])->status());
        $this->assertSame(404, $this->publicFormPage($fixture['slug'])->status());
        $this->assertSame(404, $this->submitPublicRequest($fixture['slug'])->status());
        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_a_suspended_workspace_rejects_public_requests(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->update(['status' => 'suspended']);

        $config = $this->publicConfiguration($fixture['slug']);
        $this->assertSame(403, $config->status(), $config->content());
        $this->assertSame('tenant_suspended', $this->jsonBody($config)['error']['code']);

        $page = $this->publicFormPage($fixture['slug']);
        $this->assertSame(403, $page->status(), $page->content());
        $this->assertStringContainsString('This request form is unavailable', $page->content());

        $submit = $this->submitPublicRequest($fixture['slug']);
        $this->assertSame(403, $submit->status(), $submit->content());
        $this->assertSame('tenant_suspended', $this->jsonBody($submit)['error']['code']);

        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_public_requests_are_rate_limited_per_connection(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        for ($i = 1; $i <= 5; $i++) {
            $this->createPublicRequest($fixture['slug'], ['subject' => 'Request ' . $i]);
        }

        $limited = $this->submitPublicRequest($fixture['slug'], ['subject' => 'Too many']);

        $this->assertSame(429, $limited->status(), $limited->content());
        $this->assertSame('rate_limited', $this->jsonBody($limited)['error']['code']);
        $this->assertNotNull($limited->header('Retry-After'));
        $this->assertSame(5, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_the_workspace_daily_limit_is_enforced(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->db()->table('rate_limits')->insert([
            'limiter_key' => 'public_ticket:tenant:' . $fixture['tenant_id'],
            'hits' => 101,
            'window_started_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $limited = $this->submitPublicRequest($fixture['slug']);

        $this->assertSame(429, $limited->status(), $limited->content());
        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));
    }

    private function ticketCount(int $tenantId): int
    {
        $row = $this->db()->selectOne(
            'SELECT COUNT(*) AS aggregate FROM tickets WHERE tenant_id = ?',
            [$tenantId],
        );

        return (int) ($row['aggregate'] ?? 0);
    }
}
