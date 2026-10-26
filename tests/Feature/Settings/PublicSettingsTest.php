<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class PublicSettingsTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_the_owner_can_view_and_update_workspace_settings(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $show = $this->apiGet('/api/v1/settings');
        $this->assertSame(200, $show->status(), $show->content());

        $data = $this->jsonBody($show)['data'];
        $this->assertSame('Glee Hotel', $data['name']);
        $this->assertSame('slug', $data['public_form_mode']);
        $this->assertTrue($data['public_form_enabled']);
        $this->assertSame('/request/' . $fixture['slug'], parse_url($data['public_form_url'], PHP_URL_PATH));
        $this->assertStringStartsWith('/request/', (string) parse_url($data['private_form_url'], PHP_URL_PATH));
        $this->assertSame([], $data['allowed_email_domains']);
        $this->assertTrue($data['allow_requester_replies']);
        $this->assertTrue($data['attachments_enabled']);

        $update = $this->apiPatch('/api/v1/settings', [
            'name' => 'Glee Hotel Nairobi',
            'timezone' => 'Africa/Dar_es_Salaam',
            'contact_email' => 'support@gleehotel.co.ke',
            'contact_phone' => '+254711111111',
            'mode' => 'private',
            'allowed_email_domains' => 'gleehotel.co.ke',
            'allow_requester_replies' => 0,
            'attachments_enabled' => 0,
        ]);

        $this->assertSame(200, $update->status(), $update->content());
        $updated = $this->jsonBody($update)['data'];

        $this->assertSame('Glee Hotel Nairobi', $updated['name']);
        $this->assertSame('Africa/Dar_es_Salaam', $updated['timezone']);
        $this->assertSame('support@gleehotel.co.ke', $updated['contact_email']);
        $this->assertSame('private', $updated['public_form_mode']);
        $this->assertSame(['gleehotel.co.ke'], $updated['allowed_email_domains']);
        $this->assertFalse($updated['allow_requester_replies']);
        $this->assertFalse($updated['attachments_enabled']);

        $tenant = $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Glee Hotel Nairobi', $tenant['name']);
        $this->assertSame('private', $tenant['public_form_mode']);

        $settings = json_decode((string) $tenant['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['gleehotel.co.ke'], $settings['allowed_email_domains']);
        $this->assertFalse($settings['allow_requester_replies']);
        $this->assertFalse($settings['attachments_enabled']);
    }

    public function test_settings_management_is_limited_to_owners(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $this->loginAs($agent);

        $show = $this->apiGet('/api/v1/settings');
        $this->assertSame(403, $show->status(), $show->content());
        $this->assertSame('forbidden', $this->jsonBody($show)['error']['code']);

        $update = $this->apiPatch('/api/v1/settings', ['name' => 'Taken over']);
        $this->assertSame(403, $update->status(), $update->content());

        $this->loginAs($fixture);

        $this->assertSame(200, $this->apiGet('/api/v1/settings')->status());
    }

    public function test_slug_changes_are_validated_and_recorded_in_history(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $original = $fixture['slug'];

        $short = $this->apiPatch('/api/v1/settings/slug', ['slug' => 'ab']);
        $this->assertSame(422, $short->status(), $short->content());
        $this->assertArrayHasKey('slug', $this->jsonBody($short)['error']['fields']);

        $reserved = $this->apiPatch('/api/v1/settings/slug', ['slug' => 'admin']);
        $this->assertSame(422, $reserved->status(), $reserved->content());
        $this->assertStringContainsString('reserved', $this->jsonBody($reserved)['error']['fields']['slug']);

        $otherTenantId = $this->addWorkspaceForUser($fixture['user_id'], 'Savannah Lodge');
        $takenSlug = (string) $this->db()->table('tenants')->where('id', $otherTenantId)->value('slug');

        $taken = $this->apiPatch('/api/v1/settings/slug', ['slug' => $takenSlug]);
        $this->assertSame(422, $taken->status(), $taken->content());
        $this->assertStringContainsString('already taken', $this->jsonBody($taken)['error']['fields']['slug']);

        $new = $this->uniqueSlug('glee-hotel');
        $change = $this->apiPatch('/api/v1/settings/slug', ['slug' => $new]);

        $this->assertSame(200, $change->status(), $change->content());
        $this->assertSame($new, $this->jsonBody($change)['data']['slug']);

        $history = $this->db()->table('slug_history')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('old_slug', $original)
            ->first();

        $this->assertNotNull($history);

        $same = $this->apiPatch('/api/v1/settings/slug', ['slug' => $new]);
        $this->assertSame(200, $same->status(), $same->content());

        $rows = $this->db()->select(
            'SELECT old_slug FROM slug_history WHERE tenant_id = ?',
            [$fixture['tenant_id']],
        );

        $this->assertCount(1, $rows);
    }

    public function test_regenerating_the_private_key_rotates_the_link(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->apiPatch('/api/v1/settings/public-form', ['mode' => 'private']);

        $before = $this->jsonBody($this->apiGet('/api/v1/settings'))['data']['private_form_url'];
        $oldKey = (string) basename((string) parse_url($before, PHP_URL_PATH));

        $regenerate = $this->apiPost('/api/v1/settings/public-form/regenerate');
        $this->assertSame(200, $regenerate->status(), $regenerate->content());

        $after = $this->jsonBody($regenerate)['data']['private_form_url'];
        $newKey = (string) basename((string) parse_url($after, PHP_URL_PATH));

        $this->assertNotSame($oldKey, $newKey);
        $this->assertSame(404, $this->publicConfiguration($oldKey)->status());
        $this->assertSame(200, $this->publicConfiguration($newKey)->status());
        $this->assertSame(200, $this->publicFormPage($newKey)->status());
    }

    public function test_the_settings_page_shows_qr_codes_and_whatsapp_share(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $page = $this->get('/app/settings');

        $this->assertSame(200, $page->status(), $page->content());
        $this->assertSame(2, substr_count($page->content(), 'data:image/svg+xml;base64,'));
        $this->assertStringContainsString('Share on WhatsApp', $page->content());
        $this->assertStringContainsString('https://wa.me/?text=', $page->content());
        $this->assertStringContainsString(rawurlencode('/request/' . $fixture['slug']), $page->content());
        $this->assertStringContainsString('Regenerate private key', $page->content());
    }

    public function test_the_public_slug_check_is_rate_limited(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $available = $this->get('/api/v1/slugs/check?slug=' . $this->uniqueSlug('free'), ['Accept' => 'application/json']);
        $this->assertSame(200, $available->status(), $available->content());
        $this->assertTrue($this->jsonBody($available)['data']['available']);

        $reserved = $this->get('/api/v1/slugs/check?slug=admin', ['Accept' => 'application/json']);
        $this->assertFalse($this->jsonBody($reserved)['data']['available']);

        $invalid = $this->get('/api/v1/slugs/check?slug=ab', ['Accept' => 'application/json']);
        $this->assertFalse($this->jsonBody($invalid)['data']['available']);

        for ($i = 4; $i <= 20; $i++) {
            $this->assertSame(
                200,
                $this->get('/api/v1/slugs/check?slug=check-' . $i, ['Accept' => 'application/json'])->status(),
                'Check ' . $i,
            );
        }

        $limited = $this->get('/api/v1/slugs/check?slug=too-many', ['Accept' => 'application/json']);

        $this->assertSame(429, $limited->status(), $limited->content());
        $this->assertSame('rate_limited', $this->jsonBody($limited)['error']['code']);
    }
}
