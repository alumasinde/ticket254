<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class PublicAccessIsolationTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;

    /** @var list<int> */
    private array $storageTenants = [];

    protected function tearDown(): void
    {
        foreach ($this->storageTenants as $tenantId) {
            $this->deleteTenantStorage($tenantId);
        }

        $this->cleanupTempUploads();

        parent::tearDown();
    }

    public function test_attachments_and_tracking_links_are_tenant_scoped(): void
    {
        $tenantA = $this->registerWorkspaceFixture('Glee Hotel');
        $this->storageTenants[] = $tenantA['tenant_id'];

        $this->createPublicRequest($tenantA['slug'], ['subject' => 'First']);
        $created = $this->createPublicRequest(
            $tenantA['slug'],
            ['subject' => 'Second with a photo'],
            ['photo' => $this->pngUpload()],
        );

        $ticketA = $this->ticketRow($tenantA['tenant_id'], 2);
        $attachment = $this->db()->selectOne(
            'SELECT * FROM ticket_attachments WHERE tenant_id = ? AND ticket_id = ?',
            [$tenantA['tenant_id'], (int) $ticketA['id']],
        );
        $this->assertNotNull($attachment);
        $this->assertSame('HD-000002', $created['reference']);

        $tenantB = $this->registerWorkspaceFixture('Savannah Lodge');
        $this->assertNotSame($tenantA['tenant_id'], $tenantB['tenant_id']);
        $ticketB = $this->createTicketViaApi();

        $foreignTicket = $this->get(
            '/api/v1/tickets/' . $created['reference'] . '/attachments/' . $attachment['id'],
            ['Accept' => 'application/json'],
        );
        $this->assertSame(404, $foreignTicket->status(), $foreignTicket->content());
        $this->assertSame('not_found', $this->jsonBody($foreignTicket)['error']['code']);

        $foreignAttachment = $this->get(
            '/api/v1/tickets/' . $ticketB['reference'] . '/attachments/' . $attachment['id'],
            ['Accept' => 'application/json'],
        );
        $this->assertSame(404, $foreignAttachment->status(), $foreignAttachment->content());

        $regenerate = $this->apiPost(
            '/api/v1/tickets/' . $created['reference'] . '/tracking-token/regenerate',
        );
        $this->assertSame(404, $regenerate->status(), $regenerate->content());
    }

    public function test_attachment_download_requires_a_staff_session(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->storageTenants[] = $fixture['tenant_id'];

        $created = $this->createPublicRequest($fixture['slug'], [], ['photo' => $this->pngUpload()]);
        $ticket = $this->ticketRow($fixture['tenant_id'], 1);
        $attachment = $this->db()->selectOne(
            'SELECT * FROM ticket_attachments WHERE tenant_id = ? AND ticket_id = ?',
            [$fixture['tenant_id'], (int) $ticket['id']],
        );
        $this->assertNotNull($attachment);

        $this->assertSame(204, $this->apiPost('/api/v1/auth/logout')->status());

        $download = $this->get(
            '/api/v1/tickets/' . $created['reference'] . '/attachments/' . $attachment['id'],
            ['Accept' => 'application/json'],
        );

        $this->assertSame(401, $download->status(), $download->content());
        $this->assertSame('unauthenticated', $this->jsonBody($download)['error']['code']);
    }

    public function test_the_private_key_is_unguessable_and_neutral_when_link_mode_is_slug(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $key = (string) $this->db()->table('tenants')->where('id', $fixture['tenant_id'])->value('public_key');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $this->assertSame(404, $this->publicConfiguration($key)->status());
        $this->assertSame(404, $this->publicFormPage($key)->status());
        $this->assertSame(404, $this->publicConfiguration('not-a-workspace')->status());
    }
}
