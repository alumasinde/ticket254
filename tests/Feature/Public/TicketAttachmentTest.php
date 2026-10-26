<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketAttachmentTest extends TestCase
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

    public function test_a_public_photo_is_stored_privately_and_staff_can_download_it(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->storageTenants[] = $fixture['tenant_id'];
        $photo = $this->pngUpload('reception.png');
        $bytes = (string) file_get_contents($photo['tmp_name']);

        $created = $this->createPublicRequest($fixture['slug'], [], ['photo' => $photo]);
        $ticket = $this->ticketRow($fixture['tenant_id'], 1);

        $attachment = $this->db()->selectOne(
            'SELECT * FROM ticket_attachments WHERE tenant_id = ? AND ticket_id = ?',
            [$fixture['tenant_id'], (int) $ticket['id']],
        );

        $this->assertNotNull($attachment);
        $this->assertSame('reception.png', $attachment['original_name']);
        $this->assertSame('image/png', $attachment['mime_type']);
        $this->assertSame(strlen($bytes), (int) $attachment['size_bytes']);
        $this->assertSame(hash('sha256', $bytes), $attachment['checksum_sha256']);
        $this->assertNull($attachment['uploaded_by']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', (string) $attachment['storage_key']);

        $stored = base_path('storage/private/' . $fixture['tenant_id'] . '/' . $attachment['storage_key']);
        $this->assertFileExists($stored);
        $this->assertSame($bytes, file_get_contents($stored));
        $this->assertStringStartsWith(
            str_replace('\\', '/', base_path('storage/private')),
            str_replace('\\', '/', $stored),
        );

        $detail = $this->jsonBody($this->apiGet('/api/v1/tickets/' . $created['reference']))['data'];
        $this->assertCount(1, $detail['attachments']);
        $this->assertSame('reception.png', $detail['attachments'][0]['original_name']);
        $this->assertSame('image/png', $detail['attachments'][0]['mime_type']);

        $download = $this->get(
            '/api/v1/tickets/' . $created['reference'] . '/attachments/' . $attachment['id'],
        );

        $this->assertSame(200, $download->status(), $download->content());
        $this->assertSame($bytes, $download->content());
        $this->assertSame('image/png', $download->header('Content-Type'));
        $this->assertStringContainsString('attachment; filename="reception.png"', (string) $download->header('Content-Disposition'));
        $this->assertSame('nosniff', $download->header('X-Content-Type-Options'));
        $this->assertSame('private, no-store', $download->header('Cache-Control'));
        $this->assertSame((string) strlen($bytes), $download->header('Content-Length'));
    }

    public function test_attachment_validation_rejects_bad_types_and_sizes(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $wrongExtension = $this->submitPublicRequest($fixture['slug'], [], [
            'photo' => $this->upload('photo.gif', 'not an image'),
        ]);
        $this->assertSame(422, $wrongExtension->status(), $wrongExtension->content());
        $this->assertArrayHasKey('photo', $this->jsonBody($wrongExtension)['error']['fields']);

        $wrongContents = $this->submitPublicRequest($fixture['slug'], [], [
            'photo' => $this->upload('photo.png', 'plain text pretending to be a png'),
        ]);
        $this->assertSame(422, $wrongContents->status(), $wrongContents->content());
        $this->assertStringContainsString('contents do not match', $this->jsonBody($wrongContents)['error']['fields']['photo']);

        $tooLarge = $this->pngUpload('large.png');
        $tooLarge['size'] = 6 * 1024 * 1024;

        $oversize = $this->submitPublicRequest($fixture['slug'], [], ['photo' => $tooLarge]);
        $this->assertSame(422, $oversize->status(), $oversize->content());
        $this->assertStringContainsString('5 MB', $this->jsonBody($oversize)['error']['fields']['photo']);

        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));
    }

    public function test_uploads_can_be_disabled_for_the_public_form(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $this->apiPatch('/api/v1/settings/public-form', ['attachments_enabled' => 0]);

        $response = $this->submitPublicRequest($fixture['slug'], [], ['photo' => $this->pngUpload()]);

        $this->assertSame(422, $response->status(), $response->content());
        $this->assertStringContainsString('not enabled', $this->jsonBody($response)['error']['fields']['photo']);
        $this->assertSame(0, $this->ticketCount($fixture['tenant_id']));

        $config = $this->jsonBody($this->publicConfiguration($fixture['slug']))['data'];
        $this->assertFalse($config['attachments_enabled']);
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
