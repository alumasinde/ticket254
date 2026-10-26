<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TicketSecurityTest extends TestCase
{
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_ticket_mutations_require_csrf_tokens(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $api = $this->post('/api/v1/tickets', $this->ticketPayload(), ['Accept' => 'application/json']);
        $this->assertSame(403, $api->status());
        $this->assertSame('csrf_failed', $this->jsonBody($api)['error']['code']);

        $page = $this->post('/app/tickets', $this->ticketPayload());
        $this->assertSame(403, $page->status());
        $this->assertStringContainsString('Your session token expired', $page->content());

        $this->assertSame(0, $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->count());

        $ticket = $this->createTicketViaApi();

        $patch = $this->request('PATCH', '/api/v1/tickets/' . $ticket['reference'], [
            'version' => 1,
            'subject' => 'No token',
        ], ['Accept' => 'application/json']);

        $this->assertSame(403, $patch->status());
        $this->assertSame('csrf_failed', $this->jsonBody($patch)['error']['code']);
    }

    public function test_a_role_without_ticket_permissions_is_denied(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $roleCode = 'observer_' . bin2hex(random_bytes(4));

        $this->db()->table('roles')->insert([
            'code' => $roleCode,
            'name' => 'Observer',
            'description' => 'No ticket permissions',
            'is_system' => 0,
        ]);

        $observer = $this->addMember($fixture['tenant_id'], $roleCode, 'Cynthia', 'Wairimu');
        $this->loginAs($observer);

        $api = $this->get('/api/v1/tickets', ['Accept' => 'application/json']);
        $this->assertSame(403, $api->status());
        $this->assertSame('forbidden', $this->jsonBody($api)['error']['code']);

        $page = $this->get('/app/tickets');
        $this->assertSame(403, $page->status());
        $this->assertStringContainsString('Access denied', $page->content());

        $create = $this->post('/api/v1/tickets', $this->ticketPayload(), [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
        $this->assertSame(403, $create->status());
        $this->assertSame('forbidden', $this->jsonBody($create)['error']['code']);
    }

    public function test_ticket_text_is_escaped_on_the_queue_and_detail_pages(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $ticket = $this->createTicketViaApi([
            'subject' => '<script>alert("xss")</script>',
            'requester_name' => '<img src=x onerror=alert(1)>',
            'requester_email' => 'xss@example.co.ke',
            'description' => '<b>not bold</b> & "quoted"',
        ]);

        $index = $this->get('/app/tickets');
        $this->assertSame(200, $index->status());
        $indexContent = $index->content();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $indexContent);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $indexContent);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $indexContent);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $indexContent);

        $show = $this->get('/app/tickets/' . $ticket['reference']);
        $this->assertSame(200, $show->status());
        $showContent = $show->content();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $showContent);
        $this->assertStringNotContainsString('<b>not bold</b>', $showContent);
        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt; &amp; &quot;quoted&quot;', $showContent);
    }

    public function test_search_filters_are_parameterized_against_sql_injection(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->createTicketViaApi(['subject' => 'Legitimate Glee ticket']);

        $other = $this->registerWorkspaceFixture('Savannah Lodge');
        $this->createTicketViaApi(['subject' => 'Top secret Savannah ticket']);
        $this->loginAs($fixture);

        $injections = [
            ['q' => "' OR 1=1 --"],
            ['q' => "' UNION SELECT * FROM tickets --"],
            ['status' => "open' OR '1'='1"],
            ['priority' => "high' OR 1=1 --"],
        ];

        foreach ($injections as $query) {
            $body = $this->listTicketsViaApi($query);
            $this->assertSame(0, $body['meta']['total'], http_build_query($query));
            $this->assertSame([], $body['data'], http_build_query($query));
        }

        $malformedIds = $this->listTicketsViaApi([
            'team_id' => '1 OR 1=1',
            'category_id' => '1; DROP TABLE tickets',
            'site_id' => "1' --",
        ]);

        $this->assertSame(1, $malformedIds['meta']['total']);
        $this->assertSame('Legitimate Glee ticket', $malformedIds['data'][0]['subject']);

        $page = $this->get('/app/tickets?q=' . rawurlencode("' OR 1=1 --"));
        $this->assertSame(200, $page->status());
        $this->assertStringNotContainsString('Top secret Savannah ticket', $page->content());

        $this->assertSame(1, $this->db()->table('tickets')->where('tenant_id', $fixture['tenant_id'])->count());
        $this->assertSame(1, $this->db()->table('tickets')->where('tenant_id', $other['tenant_id'])->count());
    }
}
