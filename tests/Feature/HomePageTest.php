<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HomePageTest extends TestCase
{
    public function test_home_page_renders(): void
    {
        $response = $this->get('/');

        $this->assertSame(200, $response->status());
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertStringContainsString('Requests, not WhatsApp scrollback.', $response->content());
        $this->assertStringContainsString('HudumaDesk', $response->content());
    }

    public function test_unknown_page_renders_html_404(): void
    {
        $response = $this->get('/does-not-exist');

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('Page not found', $response->content());
    }

    public function test_unknown_page_returns_json_envelope(): void
    {
        $response = $this->get('/does-not-exist', ['Accept' => 'application/json']);

        $this->assertSame(404, $response->status());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));

        $body = $this->jsonBody($response);
        $this->assertSame('not_found', $body['error']['code']);
    }

    public function test_wrong_method_returns_405_with_allow_header(): void
    {
        $response = $this->post('/');

        $this->assertSame(405, $response->status());
        $this->assertStringContainsString('GET', (string) $response->header('Allow'));
        $this->assertStringContainsString('Method not allowed', $response->content());
    }

    public function test_wrong_method_returns_json_envelope(): void
    {
        $response = $this->post('/', [], ['Accept' => 'application/json']);

        $this->assertSame(405, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('method_not_allowed', $body['error']['code']);
    }
}
