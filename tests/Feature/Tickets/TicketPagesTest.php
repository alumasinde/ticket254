<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketPagesTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_workspace_home_links_to_the_queue_and_new_ticket(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $page = $this->get('/app');

        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString('Signed in as Amina Otieno', $content);
        $this->assertStringContainsString('Glee Hotel', $content);
        $this->assertStringContainsString(route('tickets.index'), $content);
        $this->assertStringContainsString(route('tickets.create'), $content);
        $this->assertStringContainsString('Front Office', $content);
        $this->assertStringContainsString('Housekeeping', $content);
        $this->assertSame(4, $this->db()->table('teams')->where('tenant_id', $fixture['tenant_id'])->count());
    }

    public function test_create_page_renders_the_form_with_workspace_options(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $page = $this->get('/app/tickets/create');

        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString('Create a ticket', $content);
        $this->assertStringContainsString('name="requester_name"', $content);
        $this->assertStringContainsString('name="requester_email"', $content);
        $this->assertStringContainsString('name="subject"', $content);
        $this->assertStringContainsString('name="description"', $content);
        $this->assertStringContainsString('name="category_id"', $content);
        $this->assertStringContainsString('name="team_id"', $content);
        $this->assertStringContainsString('name="priority"', $content);
        $this->assertStringContainsString('name="site_id"', $content);
        $this->assertStringContainsString('name="due_at"', $content);
        $this->assertStringContainsString('Wi-Fi/Network', $content);
        $this->assertStringContainsString('Front Office', $content);
        $this->assertStringContainsString('name="_token"', $content);
        $this->assertStringContainsString('Use the category team', $content);
    }

    public function test_page_creation_redirects_to_the_new_ticket_detail(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $categoryId = $this->categoryId($fixture['tenant_id'], 'Wi-Fi/Network');

        $response = $this->post('/app/tickets', $this->ticketPayload([
            'category_id' => (string) $categoryId,
            '_token' => csrf_token(),
        ]));

        $this->assertSame(302, $response->status());
        $this->assertSame('/app/tickets/HD-000001', $response->header('Location'));

        $page = $this->get('/app/tickets/HD-000001');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Ticket HD-000001 created.', $page->content());
        $this->assertStringContainsString('No hot water in room 204', $page->content());
        $this->assertStringContainsString('IT', $page->content());

        $this->assertSame(1, (int) $this->ticketRow($fixture['tenant_id'], 1)['ticket_number']);
    }

    public function test_invalid_page_creation_returns_errors_and_old_input(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->post('/app/tickets', [
            'requester_name' => 'Njeri Wambui',
            'requester_email' => 'not-an-email',
            'subject' => '',
            'description' => '',
            '_token' => csrf_token(),
        ], ['Referer' => 'http://localhost/app/tickets/create']);

        $this->assertSame(302, $response->status());
        $this->assertSame('/app/tickets/create', $response->header('Location'));

        $errors = $this->session->get('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('requester_email', $errors);
        $this->assertArrayHasKey('subject', $errors);
        $this->assertArrayHasKey('description', $errors);

        $page = $this->get('/app/tickets/create');
        $this->assertSame(200, $page->status());
        $content = $page->content();
        $this->assertStringContainsString('The requester email field must be a valid email address.', $content);
        $this->assertStringContainsString('value="Njeri Wambui"', $content);
    }
}
