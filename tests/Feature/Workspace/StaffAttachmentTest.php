<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Core\Request;
use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class StaffAttachmentTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    /** @var list<int> */
    private array $tenantIds = [];

    protected function tearDown(): void
    {
        $this->cleanupTempUploads();

        foreach ($this->tenantIds as $tenantId) {
            $this->deleteTenantStorage($tenantId);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function latestAttachment(int $tenantId, int $ticketId): array
    {
        $row = $this->db()->table('ticket_attachments')
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($row, 'Expected an attachment row.');

        return $row;
    }

    public function test_staff_upload_and_delete_are_stored_served_and_audited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->tenantIds[] = $fixture['tenant_id'];

        $ticket = $this->createTicketViaApi();
        $reference = (string) $ticket['reference'];
        $ticketId = (int) $this->ticketRow($fixture['tenant_id'], 1)['id'];

        $upload = $this->webUpload(
            '/app/tickets/' . $reference . '/attachments',
            ['file' => $this->pngUpload('reception.png')],
        );

        $this->assertSame(302, $upload->status(), $upload->content());
        $this->assertSame('/app/tickets/' . $reference, $upload->header('Location'));

        $row = $this->latestAttachment($fixture['tenant_id'], $ticketId);
        $this->assertSame('reception.png', (string) $row['original_name']);
        $this->assertSame('image/png', (string) $row['mime_type']);
        $this->assertSame($fixture['user_id'], (int) $row['uploaded_by']);
        $this->assertNull($row['message_id']);

        $path = base_path('storage/private/' . $fixture['tenant_id'] . '/' . (string) $row['storage_key']);
        $this->assertFileExists($path);

        $audit = $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'attachment.uploaded')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($fixture['user_id'], (int) $audit['actor_user_id']);
        $this->assertSame((string) $row['id'], (string) $audit['resource_id']);
        $metadata = json_decode((string) $audit['metadata'], true);
        $this->assertSame($ticketId, $metadata['ticket_id']);
        $this->assertSame('reception.png', $metadata['name']);
        $this->assertArrayNotHasKey('storage_key', $metadata);

        $page = $this->get('/app/tickets/' . $reference);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('reception.png', $page->content());
        $this->assertStringContainsString('Attachment added.', $page->content());

        $download = $this->get(
            '/api/v1/tickets/' . $reference . '/attachments/' . $row['id'],
            ['Accept' => 'application/json'],
        );
        $this->assertSame(200, $download->status());
        $this->assertSame('image/png', (string) $download->header('Content-Type'));
        $this->assertStringContainsString('reception.png', (string) $download->header('Content-Disposition'));
        $this->assertStringContainsString('nosniff', strtolower((string) $download->header('X-Content-Type-Options')));

        $delete = $this->webDelete('/app/tickets/' . $reference . '/attachments/' . $row['id']);
        $this->assertSame(302, $delete->status(), $delete->content());
        $this->assertSame(0, $this->db()->table('ticket_attachments')->where('id', (int) $row['id'])->count());
        $this->assertFileDoesNotExist($path);

        $this->assertSame(1, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'attachment.deleted')
            ->count());

        $after = $this->get('/app/tickets/' . $reference);
        $this->assertStringContainsString('Attachment removed.', $after->content());
        $this->assertStringContainsString('No attachments yet.', $after->content());
    }

    public function test_upload_validation_rejects_missing_wrong_and_oversized_files(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->tenantIds[] = $fixture['tenant_id'];

        $ticket = $this->createTicketViaApi();
        $reference = (string) $ticket['reference'];
        $ticketId = (int) $this->ticketRow($fixture['tenant_id'], 1)['id'];
        $uri = '/app/tickets/' . $reference . '/attachments';

        $missing = $this->webUpload($uri, []);
        $this->assertSame(302, $missing->status());
        $this->assertArrayHasKey('file', $this->session->get('errors'));

        $wrongType = $this->webUpload($uri, ['file' => $this->upload('clip.gif', 'GIF89a' . str_repeat("\0", 32))]);
        $this->assertSame(302, $wrongType->status());
        $this->assertStringContainsString('JPG, PNG, PDF or TXT', (string) $this->session->get('errors')['file']);

        $mismatch = $this->webUpload($uri, ['file' => $this->upload('fake.png', 'not really a png')]);
        $this->assertSame(302, $mismatch->status());
        $this->assertStringContainsString('do not match', (string) $this->session->get('errors')['file']);

        $oversize = $this->webUpload($uri, [
            'file' => $this->upload('notes.txt', str_repeat('a', 5 * 1024 * 1024 + 1)),
        ]);
        $this->assertSame(302, $oversize->status());
        $this->assertStringContainsString('smaller than 5 MB', (string) $this->session->get('errors')['file']);

        $this->assertSame(0, $this->db()->table('ticket_attachments')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('ticket_id', $ticketId)
            ->count());
        $this->assertSame(0, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'attachment.uploaded')
            ->count());
    }

    public function test_attachments_are_tenant_scoped_and_csrf_is_required(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');
        $this->tenantIds[] = $glee['tenant_id'];
        $gleeTicket = $this->createTicketViaApi();
        $gleeTicketId = (int) $this->ticketRow($glee['tenant_id'], 1)['id'];

        $upload = $this->webUpload(
            '/app/tickets/' . $gleeTicket['reference'] . '/attachments',
            ['file' => $this->pngUpload('glee.png')],
        );
        $this->assertSame(302, $upload->status(), $upload->content());
        $gleeAttachment = $this->latestAttachment($glee['tenant_id'], $gleeTicketId);

        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $this->tenantIds[] = $savannah['tenant_id'];
        $savannahTicket = $this->createTicketViaApi();
        $reference = (string) $savannahTicket['reference'];

        $crossTenantDelete = $this->webDelete(
            '/app/tickets/' . $reference . '/attachments/' . $gleeAttachment['id'],
        );
        $this->assertSame(404, $crossTenantDelete->status());
        $this->assertSame(1, $this->db()->table('ticket_attachments')
            ->where('id', (int) $gleeAttachment['id'])
            ->count());

        $crossTenantDownload = $this->get(
            '/api/v1/tickets/' . $reference . '/attachments/' . $gleeAttachment['id'],
            ['Accept' => 'application/json'],
        );
        $this->assertSame(404, $crossTenantDownload->status());

        $this->loginAs($glee);

        $withoutToken = $this->app->handle(Request::create(
            'POST',
            '/app/tickets/' . $gleeTicket['reference'] . '/attachments',
            [],
            [],
            [],
            ['file' => $this->pngUpload('no-token.png')],
        ));
        $this->assertSame(403, $withoutToken->status());
        $this->assertSame(1, $this->db()->table('ticket_attachments')
            ->where('tenant_id', $glee['tenant_id'])
            ->count());
    }
}
