<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class PlatformConsoleTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    /**
     * @param array{user_id: int} $fixture
     */
    private function makePlatformAdmin(array $fixture): void
    {
        $this->db()->table('users')
            ->where('id', $fixture['user_id'])
            ->update(['is_platform_admin' => 1]);
    }

    public function test_platform_console_requires_the_platform_flag_and_renders_metadata_only(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $blockedPage = $this->get('/platform', ['Accept' => 'application/json']);
        $this->assertSame(403, $blockedPage->status(), $blockedPage->content());
        $this->assertSame('forbidden', $this->jsonBody($blockedPage)['error']['code']);

        $blockedApi = $this->apiGet('/api/v1/platform/overview');
        $this->assertSame(403, $blockedApi->status());

        $this->makePlatformAdmin($fixture);

        $overviewPage = $this->get('/platform');
        $this->assertSame(200, $overviewPage->status());
        $this->assertStringContainsString('Operations overview', $overviewPage->content());
        $this->assertStringContainsString('Glee Hotel', $overviewPage->content());

        $tenantsPage = $this->get('/platform/tenants');
        $this->assertSame(200, $tenantsPage->status());
        $this->assertStringContainsString($fixture['slug'], $tenantsPage->content());
        $this->assertStringContainsString('No plan', $tenantsPage->content());

        $tenantPage = $this->get('/platform/tenants/' . $fixture['tenant_id']);
        $this->assertSame(200, $tenantPage->status());
        $this->assertStringContainsString('Glee Hotel', $tenantPage->content());
        $this->assertStringContainsString('Suspend workspace', $tenantPage->content());
        $this->assertStringContainsString('Usage', $tenantPage->content());

        $healthPage = $this->get('/platform/health');
        $this->assertSame(200, $healthPage->status());
        $this->assertStringContainsString('Database', $healthPage->content());
        $this->assertStringContainsString('Up', $healthPage->content());

        $overview = $this->jsonBody($this->apiGet('/api/v1/platform/overview'))['data'];
        $this->assertGreaterThanOrEqual(1, $overview['tenants_total']);
        $this->assertGreaterThanOrEqual(1, $overview['users_total']);
        $this->assertArrayHasKey('outbox_pending', $overview);

        $list = $this->jsonBody($this->apiGet(
            '/api/v1/platform/tenants?q=' . rawurlencode($fixture['slug']),
        ))['data'];
        $this->assertCount(1, $list);
        $this->assertSame('Glee Hotel', $list[0]['name']);
        $this->assertSame('active', $list[0]['status']);

        $detail = $this->jsonBody($this->apiGet(
            '/api/v1/platform/tenants/' . $fixture['tenant_id'],
        ))['data'];
        $this->assertSame($fixture['tenant_id'], $detail['id']);
        $this->assertSame($fixture['user_id'], (int) $this->db()->table('tenant_memberships')
            ->where('tenant_id', $fixture['tenant_id'])
            ->value('user_id'));
        $this->assertGreaterThanOrEqual(1, $detail['usage']['agents']);
        $this->assertCount(4, $detail['plans']);

        $health = $this->jsonBody($this->apiGet('/api/v1/platform/health'))['data'];
        $this->assertTrue($health['database']['reachable']);
        $this->assertArrayHasKey('pending', $health['outbox']);

        $this->assertSame(404, $this->apiGet('/api/v1/platform/tenants/999999')->status());
        $this->assertSame(404, $this->get('/platform/tenants/999999')->status());
    }

    public function test_suspending_a_workspace_blocks_members_and_reactivation_restores_access(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->makePlatformAdmin($fixture);

        $noToken = $this->post('/api/v1/platform/tenants/' . $fixture['tenant_id'] . '/suspend', [
            'reason' => 'No token supplied.',
        ], ['Accept' => 'application/json']);
        $this->assertSame(403, $noToken->status(), $noToken->content());
        $this->assertSame('csrf_failed', $this->jsonBody($noToken)['error']['code']);

        $suspended = $this->apiPost('/api/v1/platform/tenants/' . $fixture['tenant_id'] . '/suspend', [
            'reason' => 'Non-payment for two months.',
        ]);
        $this->assertSame(200, $suspended->status(), $suspended->content());
        $data = $this->jsonBody($suspended)['data'];
        $this->assertSame('suspended', $data['status']);
        $this->assertSame('Non-payment for two months.', $data['suspended_reason']);
        $this->assertNotNull($data['suspended_at']);

        $tenant = $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->first();
        $this->assertSame('suspended', (string) $tenant['status']);
        $this->assertSame('Non-payment for two months.', (string) $tenant['suspended_reason']);

        $audit = $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'tenant.suspended')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('Non-payment for two months.', json_decode((string) $audit['metadata'], true)['reason']);

        $blocked = $this->get('/app', ['Accept' => 'application/json']);
        $this->assertSame(403, $blocked->status(), $blocked->content());
        $this->assertSame('tenant_suspended', $this->jsonBody($blocked)['error']['code']);

        $blockedPage = $this->get('/app');
        $this->assertSame(403, $blockedPage->status());
        $this->assertStringContainsString('This workspace is unavailable.', $blockedPage->content());

        $suspendedPage = $this->get('/platform/tenants/' . $fixture['tenant_id']);
        $this->assertSame(200, $suspendedPage->status());
        $this->assertStringContainsString('Reactivate workspace', $suspendedPage->content());
        $this->assertStringContainsString('Non-payment for two months.', $suspendedPage->content());

        $reactivated = $this->apiPost('/api/v1/platform/tenants/' . $fixture['tenant_id'] . '/reactivate');
        $this->assertSame(200, $reactivated->status(), $reactivated->content());
        $this->assertSame('active', $this->jsonBody($reactivated)['data']['status']);

        $tenant = $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->first();
        $this->assertSame('active', (string) $tenant['status']);
        $this->assertNull($tenant['suspended_at']);
        $this->assertNull($tenant['suspended_reason']);
        $this->assertSame(1, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'tenant.reactivated')
            ->count());

        $home = $this->get('/app');
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('Today at Glee Hotel', $home->content());
    }

    public function test_subscription_updates_validate_input_and_are_audited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->makePlatformAdmin($fixture);
        $uri = '/api/v1/platform/tenants/' . $fixture['tenant_id'] . '/subscription';

        $badPlan = $this->apiPatch($uri, ['plan_code' => 'gold', 'status' => 'active']);
        $this->assertSame(422, $badPlan->status(), $badPlan->content());
        $this->assertArrayHasKey('plan_code', $this->jsonBody($badPlan)['error']['fields']);

        $badStatus = $this->apiPatch($uri, ['plan_code' => 'team', 'status' => 'frozen']);
        $this->assertSame(422, $badStatus->status(), $badStatus->content());
        $this->assertArrayHasKey('status', $this->jsonBody($badStatus)['error']['fields']);

        $badDate = $this->apiPatch($uri, [
            'plan_code' => 'team',
            'status' => 'active',
            'current_period_end' => '2026-02-31',
        ]);
        $this->assertSame(422, $badDate->status(), $badDate->content());
        $this->assertArrayHasKey('current_period_end', $this->jsonBody($badDate)['error']['fields']);

        $updated = $this->apiPatch($uri, [
            'plan_code' => 'team',
            'status' => 'active',
            'current_period_end' => '2026-12-31',
            'notes' => 'M-Pesa QJK7X2L9',
        ]);
        $this->assertSame(200, $updated->status(), $updated->content());

        $subscription = $this->db()->table('subscriptions')
            ->where('tenant_id', $fixture['tenant_id'])
            ->first();
        $this->assertNotNull($subscription);
        $this->assertSame(3, (int) $subscription['plan_id']);
        $this->assertSame('active', (string) $subscription['status']);
        $this->assertSame('2026-12-31 23:59:59', (string) $subscription['current_period_end']);
        $this->assertSame('M-Pesa QJK7X2L9', (string) $subscription['notes']);
        $this->assertNull($subscription['cancelled_at']);

        $audit = $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'tenant.subscription_updated')
            ->first();
        $this->assertNotNull($audit);
        $metadata = json_decode((string) $audit['metadata'], true);
        $this->assertSame('team', $metadata['plan']);
        $this->assertSame('active', $metadata['status']);

        $detail = $this->jsonBody($this->apiGet(
            '/api/v1/platform/tenants/' . $fixture['tenant_id'],
        ))['data'];
        $this->assertSame('team', $detail['plan']['code']);
        $this->assertSame('Team', $detail['plan']['name']);
        $this->assertSame('active', $detail['plan']['status']);
        $this->assertStringStartsWith('2026-12-31', (string) $detail['plan']['current_period_end']);

        $show = $this->get('/platform/tenants/' . $fixture['tenant_id']);
        $this->assertStringContainsString('Team', $show->content());

        $web = $this->webPatch('/platform/tenants/' . $fixture['tenant_id'] . '/subscription', [
            'plan_code' => 'starter',
            'status' => 'trial',
            'current_period_end' => '2027-01-31',
            'notes' => 'Trial extension',
        ]);
        $this->assertSame(302, $web->status(), $web->content());
        $this->assertSame('/platform/tenants/' . $fixture['tenant_id'], $web->header('Location'));

        $subscription = $this->db()->table('subscriptions')
            ->where('tenant_id', $fixture['tenant_id'])
            ->first();
        $this->assertSame(2, (int) $subscription['plan_id']);
        $this->assertSame('trial', (string) $subscription['status']);
        $this->assertSame('Trial extension', (string) $subscription['notes']);
    }
}
