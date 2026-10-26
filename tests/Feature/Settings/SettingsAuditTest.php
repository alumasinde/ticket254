<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class SettingsAuditTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    /**
     * @return array<string, mixed>
     */
    private function auditRow(int $tenantId, string $action): array
    {
        $row = $this->db()->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where('action', $action)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($row, "Expected audit action [$action].");

        return $row;
    }

    public function test_profile_changes_are_audited_without_storing_values(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $page = $this->get('/app/settings');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Workspace and public form', $page->content());
        $this->assertStringContainsString('Public request link', $page->content());

        $update = $this->webPatch('/app/settings', [
            'name' => 'Glee Hotel Nairobi',
            'timezone' => 'Africa/Nairobi',
            'contact_email' => 'help@gleehotel.co.ke',
            'contact_phone' => '+254700000009',
        ]);

        $this->assertSame(302, $update->status(), $update->content());
        $this->assertSame('/app/settings', $update->header('Location'));

        $tenant = $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->first();
        $this->assertSame('Glee Hotel Nairobi', (string) $tenant['name']);
        $this->assertSame('help@gleehotel.co.ke', (string) $tenant['contact_email']);
        $this->assertSame('+254700000009', (string) $tenant['contact_phone']);

        $audit = $this->auditRow($fixture['tenant_id'], 'settings.profile_updated');
        $this->assertSame($fixture['user_id'], (int) $audit['actor_user_id']);
        $this->assertSame('tenant', (string) $audit['resource_type']);
        $this->assertSame((string) $fixture['tenant_id'], (string) $audit['resource_id']);

        $metadata = json_decode((string) $audit['metadata'], true);
        $this->assertSame(
            ['name', 'timezone', 'contact_email', 'contact_phone'],
            $metadata['changed'],
        );
        $this->assertStringNotContainsString('help@gleehotel.co.ke', (string) $audit['metadata']);
        $this->assertStringNotContainsString('+254700000009', (string) $audit['metadata']);
    }

    public function test_slug_changes_and_key_regeneration_are_audited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $oldSlug = (string) $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->value('slug');
        $newSlug = 'glee-nairobi-' . bin2hex(random_bytes(3));

        $slug = $this->webPatch('/app/settings/slug', ['slug' => $newSlug]);
        $this->assertSame(302, $slug->status(), $slug->content());

        $tenant = $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->first();
        $this->assertSame($newSlug, (string) $tenant['slug']);

        $history = $this->db()->table('slug_history')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('old_slug', $oldSlug)
            ->first();
        $this->assertNotNull($history);

        $slugAudit = $this->auditRow($fixture['tenant_id'], 'settings.slug_changed');
        $metadata = json_decode((string) $slugAudit['metadata'], true);
        $this->assertSame($oldSlug, $metadata['from']);
        $this->assertSame($newSlug, $metadata['to']);

        $oldKey = (string) $tenant['public_key'];

        $regenerate = $this->webPost('/app/settings/regenerate');
        $this->assertSame(302, $regenerate->status(), $regenerate->content());

        $newKey = (string) $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->value('public_key');
        $this->assertNotSame($oldKey, $newKey);

        $keyAudit = $this->auditRow($fixture['tenant_id'], 'settings.key_regenerated');
        $this->assertNull($keyAudit['metadata']);
        $this->assertStringNotContainsString($oldKey, (string) $keyAudit['metadata']);
        $this->assertStringNotContainsString($newKey, (string) $keyAudit['metadata']);
    }

    public function test_public_form_settings_update_via_api_is_audited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->apiPatch('/api/v1/settings/public-form', [
            'mode' => 'private',
            'allowed_email_domains' => 'gleehotel.co.ke, gleenairobi.com',
            'allow_requester_replies' => '1',
            'attachments_enabled' => '0',
        ]);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];
        $this->assertSame('private', $data['public_form_mode']);
        $this->assertSame(['gleehotel.co.ke', 'gleenairobi.com'], $data['allowed_email_domains']);
        $this->assertTrue($data['allow_requester_replies']);
        $this->assertFalse($data['attachments_enabled']);

        $audit = $this->auditRow($fixture['tenant_id'], 'settings.public_form_updated');
        $metadata = json_decode((string) $audit['metadata'], true);
        $this->assertSame(
            ['mode', 'allowed_email_domains', 'allow_requester_replies', 'attachments_enabled'],
            $metadata['changed'],
        );
        $this->assertStringNotContainsString('gleehotel.co.ke', (string) $audit['metadata']);

        $regenerate = $this->post('/api/v1/settings/public-form/regenerate', [], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
        $this->assertSame(200, $regenerate->status(), $regenerate->content());
        $this->assertNotNull($this->auditRow($fixture['tenant_id'], 'settings.key_regenerated'));
    }

    public function test_invalid_settings_are_rejected_and_not_audited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $before = $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'settings.profile_updated')
            ->count();

        $badEmail = $this->webPatch('/app/settings', [
            'name' => 'Glee Hotel',
            'timezone' => 'Africa/Nairobi',
            'contact_email' => 'not-an-email',
            'contact_phone' => '',
        ]);

        $this->assertSame(302, $badEmail->status(), $badEmail->content());
        $this->assertArrayHasKey('contact_email', $this->session->get('errors'));

        $noName = $this->webPatch('/app/settings', [
            'name' => '',
            'timezone' => 'Africa/Nairobi',
            'contact_email' => '',
            'contact_phone' => '',
        ]);

        $this->assertSame(302, $noName->status(), $noName->content());
        $this->assertArrayHasKey('name', $this->session->get('errors'));

        $this->assertSame($before, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'settings.profile_updated')
            ->count());

        $badSlug = $this->apiPatch('/api/v1/settings/slug', ['slug' => 'Bad Slug!']);
        $this->assertSame(422, $badSlug->status(), $badSlug->content());
        $this->assertArrayHasKey('slug', $this->jsonBody($badSlug)['error']['fields']);
        $this->assertSame(0, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'settings.slug_changed')
            ->count());
    }

    public function test_only_owners_can_open_or_change_settings(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $admin = $this->addMember($fixture['tenant_id'], 'admin', 'Mercy', 'Wanjiku');

        $this->loginAs($admin);

        $this->assertSame(403, $this->get('/app/settings')->status());
        $this->assertSame(403, $this->apiGet('/api/v1/settings')->status());
        $this->assertSame(403, $this->webPatch('/app/settings', [
            'name' => 'Admin Rename',
            'timezone' => 'Africa/Nairobi',
        ])->status());

        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->loginAs($agent);

        $this->assertSame(403, $this->get('/app/settings')->status());
        $this->assertSame(403, $this->apiGet('/api/v1/settings')->status());

        $this->assertSame('Glee Hotel', (string) $this->db()->table('tenants')
            ->where('id', $fixture['tenant_id'])
            ->value('name'));
    }
}
