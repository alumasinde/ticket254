<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class CsrfTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_api_mutations_require_a_valid_csrf_token(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $denied = $this->post('/api/v1/auth/logout', [], ['Accept' => 'application/json']);

        $this->assertSame(403, $denied->status());
        $body = $this->jsonBody($denied);
        $this->assertSame('csrf_failed', $body['error']['code']);
        $this->assertSame('Your session token expired. Refresh the page and try again.', $body['error']['message']);
        $this->assertNotNull($this->sessionAuth());

        $me = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $token = (string) $this->jsonBody($me)['data']['csrf_token'];
        $this->assertNotSame('', $token);

        $allowed = $this->post('/api/v1/auth/logout', [], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => $token,
        ]);

        $this->assertSame(204, $allowed->status());
        $this->assertSame('', $allowed->content());
        $this->assertNull($this->sessionAuth());

        $after = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->assertSame(401, $after->status());
        $this->assertSame('unauthenticated', $this->jsonBody($after)['error']['code']);
    }

    public function test_login_discards_the_old_token_and_issues_a_new_one(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $old = csrf_token();
        $this->assertSame(64, strlen($old));

        $response = $this->loginAs($fixture);

        $this->assertSame(200, $response->status(), $response->content());
        $this->assertNull($this->session->get('_token'));

        $me = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $fresh = (string) $this->jsonBody($me)['data']['csrf_token'];

        $this->assertSame(64, strlen($fresh));
        $this->assertNotSame($old, $fresh);
        $this->assertSame($fresh, $this->session->get('_token'));
    }

    public function test_page_logout_requires_the_form_token(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');
        $this->get('/app');
        $token = (string) $this->session->get('_token');
        $this->assertNotSame('', $token);

        $denied = $this->post('/logout');

        $this->assertSame(403, $denied->status());
        $this->assertStringContainsString('Your session token expired', $denied->content());
        $this->assertNotNull($this->sessionAuth());

        $allowed = $this->post('/logout', ['_token' => $token]);

        $this->assertSame(302, $allowed->status());
        $this->assertSame('/login', $allowed->header('Location'));
        $this->assertNull($this->sessionAuth());
    }
}
