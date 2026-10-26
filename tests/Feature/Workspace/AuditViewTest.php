<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Services\Audit\AuditLogger;
use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class AuditViewTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;

    private function auditLogger(): AuditLogger
    {
        return $this->app->container()->get(AuditLogger::class);
    }

    public function test_audit_view_returns_tenant_entries_with_sanitised_metadata_and_action_filter(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $logger = $this->auditLogger();

        $logger->record(null, $fixture['tenant_id'], $fixture['user_id'], 'privacy.test', 'tenant', $fixture['tenant_id'], [
            'password' => 'hunter2-password',
            'api_token' => 'tok_live_123',
            'email' => 'secret.person@example.co.ke',
            'visible' => 'Amina',
            'nested' => [
                'secret' => 'do-not-store-me',
                'count' => 2,
            ],
        ]);
        $logger->record(null, $fixture['tenant_id'], $fixture['user_id'], 'other.event', 'tenant', $fixture['tenant_id'], [
            'visible' => 'other',
        ]);

        $response = $this->apiGet('/api/v1/audit-logs?action=privacy.test');
        $this->assertSame(200, $response->status(), $response->content());
        $body = $this->jsonBody($response);

        $this->assertCount(1, $body['data']);
        $row = $body['data'][0];
        $this->assertSame('privacy.test', $row['action']);
        $this->assertSame('tenant', $row['resource_type']);
        $this->assertSame($fixture['user_id'], $row['actor']['id']);
        $this->assertSame('Amina Otieno', $row['actor']['name']);
        $this->assertSame('Amina', $row['metadata']['visible']);
        $this->assertSame(2, $row['metadata']['nested']['count']);
        $this->assertArrayNotHasKey('password', $row['metadata']);
        $this->assertArrayNotHasKey('api_token', $row['metadata']);
        $this->assertArrayNotHasKey('email', $row['metadata']);
        $this->assertArrayNotHasKey('secret', $row['metadata']['nested']);
        $this->assertContains('privacy.test', $body['actions']);
        $this->assertContains('other.event', $body['actions']);

        $page = $this->get('/app/audit?action=privacy.test');
        $this->assertSame(200, $page->status());
        $content = $page->content();
        $this->assertStringContainsString('privacy.test', $content);
        $this->assertStringContainsString('Amina Otieno', $content);
        $this->assertStringContainsString('Amina', $content);
        $this->assertStringContainsString('&quot;count&quot;:2', $content);
        $this->assertStringNotContainsString('hunter2-password', $content);
        $this->assertStringNotContainsString('tok_live_123', $content);
        $this->assertStringNotContainsString('secret.person@example.co.ke', $content);
        $this->assertStringNotContainsString('do-not-store-me', $content);
    }

    public function test_audit_entries_never_leak_across_tenants(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');
        $logger = $this->auditLogger();
        $logger->record(null, $glee['tenant_id'], $glee['user_id'], 'shared.action', 'tenant', $glee['tenant_id'], [
            'marker' => 'Glee only',
        ]);

        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $logger->record(null, $savannah['tenant_id'], $savannah['user_id'], 'shared.action', 'tenant', $savannah['tenant_id'], [
            'marker' => 'Savannah only',
        ]);

        $savannahBody = $this->jsonBody($this->apiGet('/api/v1/audit-logs?action=shared.action'));
        $this->assertCount(1, $savannahBody['data']);
        $this->assertSame('Savannah only', $savannahBody['data'][0]['metadata']['marker']);

        $this->loginAs($glee);

        $gleeBody = $this->jsonBody($this->apiGet('/api/v1/audit-logs?action=shared.action'));
        $this->assertCount(1, $gleeBody['data']);
        $this->assertSame('Glee only', $gleeBody['data'][0]['metadata']['marker']);

        $page = $this->get('/app/audit?tenant_id=' . $savannah['tenant_id']);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Glee only', $page->content());
        $this->assertStringNotContainsString('Savannah only', $page->content());
    }

    public function test_agents_cannot_read_the_audit_log_but_admins_can(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $this->loginAs($agent);

        $this->assertSame(403, $this->get('/app/audit')->status());
        $this->assertSame(403, $this->apiGet('/api/v1/audit-logs')->status());

        $admin = $this->addMember($fixture['tenant_id'], 'admin', 'Mercy', 'Wanjiku');
        $this->loginAs($admin);

        $page = $this->get('/app/audit');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Audit log', $page->content());

        $response = $this->apiGet('/api/v1/audit-logs');
        $this->assertSame(200, $response->status());
        $this->assertArrayHasKey('meta', $this->jsonBody($response));
    }
}
