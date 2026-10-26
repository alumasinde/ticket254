<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Core\Response;
use App\Core\Router;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_api_login_returns_workspaces_and_regenerates_the_session(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->assertNull($this->db()->table('users')->where('id', $fixture['user_id'])->value('last_login_at'));

        $this->session->put('_token', 'stale-token');
        $before = $this->session->regenerations;

        $response = $this->loginAs($fixture);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame($fixture['user_id'], $data['user']['id']);
        $this->assertSame('Amina', $data['user']['first_name']);
        $this->assertSame('Otieno', $data['user']['last_name']);
        $this->assertCount(1, $data['workspaces']);
        $this->assertSame($fixture['tenant_id'], $data['workspaces'][0]['id']);
        $this->assertSame('Glee Hotel', $data['workspaces'][0]['name']);
        $this->assertSame($fixture['slug'], $data['workspaces'][0]['slug']);
        $this->assertSame('owner', $data['workspaces'][0]['role']);
        $this->assertSame($fixture['tenant_id'], $data['active_workspace_id']);

        $this->assertSame($before + 1, $this->session->regenerations);
        $this->assertNull($this->session->get('_token'));

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame($fixture['user_id'], $auth['user_id']);
        $this->assertSame($fixture['tenant_id'], $auth['tenant_id']);
        $this->assertSame(1, $auth['auth_version']);
        $this->assertNotNull($this->db()->table('users')->where('id', $fixture['user_id'])->value('last_login_at'));
    }

    public function test_unknown_email_and_wrong_password_return_the_same_error(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->destroy();

        $wrongPassword = $this->post('/api/v1/auth/login', [
            'email' => $fixture['email'],
            'password' => 'not-the-password',
        ], ['Accept' => 'application/json']);

        $unknownEmail = $this->post('/api/v1/auth/login', [
            'email' => $this->uniqueEmail('nobody'),
            'password' => 'not-the-password',
        ], ['Accept' => 'application/json']);

        foreach ([$wrongPassword, $unknownEmail] as $response) {
            $this->assertSame(401, $response->status());
            $body = $this->jsonBody($response);
            $this->assertSame('invalid_credentials', $body['error']['code']);
            $this->assertSame('The email or password is incorrect.', $body['error']['message']);
        }

        $this->assertNull($this->sessionAuth());
    }

    public function test_login_chooses_the_most_recent_active_membership(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $newer = $this->addWorkspaceForUser(
            $fixture['user_id'],
            'Savannah Lodge',
            'agent',
            gmdate('Y-m-d H:i:s', time() + 120),
        );
        $suspended = $this->addWorkspaceForUser(
            $fixture['user_id'],
            'Suspended Lodge',
            'owner',
            gmdate('Y-m-d H:i:s', time() + 180),
            'suspended',
        );
        $disabled = $this->addWorkspaceForUser(
            $fixture['user_id'],
            'Disabled Lodge',
            'owner',
            gmdate('Y-m-d H:i:s', time() + 240),
            'active',
            'disabled',
        );

        $response = $this->loginAs($fixture);

        $this->assertSame(200, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];
        $ids = array_column($data['workspaces'], 'id');

        $this->assertSame([$newer, $fixture['tenant_id']], $ids);
        $this->assertSame($newer, $data['active_workspace_id']);
        $this->assertNotContains($suspended, $ids);
        $this->assertNotContains($disabled, $ids);
        $this->assertSame('agent', $data['workspaces'][0]['role']);
    }

    public function test_disabled_users_cannot_sign_in(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->destroy();

        $this->db()->table('users')->where('id', $fixture['user_id'])->update(['status' => 'disabled']);

        $response = $this->loginAs($fixture);

        $this->assertSame(401, $response->status());
        $this->assertSame('invalid_credentials', $this->jsonBody($response)['error']['code']);
        $this->assertNull($this->sessionAuth());
    }

    public function test_signed_in_users_are_redirected_away_from_the_login_page(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->get('/login');

        $this->assertSame(302, $response->status());
        $this->assertSame('/app', $response->header('Location'));
    }

    public function test_page_login_failure_redirects_with_an_email_error_and_old_input(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->destroy();

        $this->get('/login');
        $token = (string) $this->session->get('_token');

        $response = $this->post('/login', [
            'email' => $fixture['email'],
            'password' => 'not-the-password',
            '_token' => $token,
        ], ['Referer' => 'http://localhost/login']);

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->header('Location'));

        $errors = $this->session->get('errors');
        $this->assertIsArray($errors);
        $this->assertSame('The email or password is incorrect.', $errors['email']);

        $old = $this->session->get('_old_input');
        $this->assertIsArray($old);
        $this->assertSame($fixture['email'], $old['email']);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertNull($this->sessionAuth());
    }

    public function test_page_login_success_redirects_to_the_workspace(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->destroy();

        $this->get('/login');
        $token = (string) $this->session->get('_token');

        $response = $this->post('/login', [
            'email' => $fixture['email'],
            'password' => $fixture['password'],
            '_token' => $token,
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/app', $response->header('Location'));

        $page = $this->get('/app');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Glee Hotel', $page->content());
        $this->assertStringContainsString('Signed in as Amina Otieno', $page->content());
        $this->assertStringContainsString('Signed in.', $page->content());
    }

    public function test_idle_sessions_expire_and_are_destroyed(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->put('_auth_last_activity', time() - (121 * 60));

        $response = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(401, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('unauthenticated', $body['error']['code']);
        $this->assertSame('Your session expired. Please sign in again.', $body['error']['message']);
        $this->assertNull($this->sessionAuth());

        $again = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->assertSame(401, $again->status());
        $this->assertSame('Please sign in to continue.', $this->jsonBody($again)['error']['message']);
    }

    public function test_absolute_session_lifetime_is_enforced(): void
    {
        $this->registerWorkspaceFixture('Glee Hotel');
        $this->session->put('_auth_created_at', time() - (721 * 60));

        $response = $this->get('/app');

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->header('Location'));
        $this->assertNull($this->sessionAuth());
    }

    public function test_auth_version_growth_invalidates_the_existing_session(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->app->container()->get(UserRepository::class)->incrementAuthVersion($fixture['user_id']);

        $response = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(401, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('unauthenticated', $body['error']['code']);
        $this->assertSame('Your session is no longer valid. Please sign in again.', $body['error']['message']);
        $this->assertNull($this->sessionAuth());

        $again = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->assertSame(401, $again->status());
        $this->assertSame('Please sign in to continue.', $this->jsonBody($again)['error']['message']);
    }

    public function test_role_and_permissions_are_reloaded_on_each_request(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $router = $this->app->container()->get(Router::class);
        $router->get('/probe/manage-settings', static function (): Response {
            return Response::json(['data' => ['allowed' => true]]);
        })->middleware(['auth', 'tenant'])->can('settings.manage');

        $owner = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $data = $this->jsonBody($owner)['data'];
        $this->assertSame('owner', $data['role']);
        $this->assertCount(18, $data['permissions']);
        $this->assertContains('settings.manage', $data['permissions']);
        $this->assertContains('tickets.assign', $data['permissions']);
        $this->assertSame(200, $this->get('/probe/manage-settings', ['Accept' => 'application/json'])->status());

        $agentRoleId = $this->app->container()->get(RoleRepository::class)->idByCode('agent');
        $this->db()->table('tenant_memberships')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('user_id', $fixture['user_id'])
            ->update(['role_id' => $agentRoleId]);

        $agent = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $data = $this->jsonBody($agent)['data'];
        $this->assertSame('agent', $data['role']);
        $this->assertSame([
            'reports.view_team',
            'tickets.assign_self',
            'tickets.attach',
            'tickets.create',
            'tickets.note',
            'tickets.reply',
            'tickets.status',
            'tickets.view_team',
        ], $data['permissions']);
        $this->assertNotContains('settings.manage', $data['permissions']);
        $this->assertNotContains('tickets.assign', $data['permissions']);

        $denied = $this->get('/probe/manage-settings', ['Accept' => 'application/json']);
        $this->assertSame(403, $denied->status());
        $this->assertSame('forbidden', $this->jsonBody($denied)['error']['code']);
    }

    public function test_me_returns_only_safe_fields_and_a_csrf_token(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $response = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(200, $response->status());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame(['id', 'first_name', 'last_name', 'email'], array_keys($data['user']));
        $this->assertSame(['id', 'name', 'timezone'], array_keys($data['workspace']));
        $this->assertSame($fixture['user_id'], $data['user']['id']);
        $this->assertSame($fixture['email'], $data['user']['email']);
        $this->assertSame($fixture['tenant_id'], $data['workspace']['id']);
        $this->assertSame('Glee Hotel', $data['workspace']['name']);
        $this->assertSame('Africa/Nairobi', $data['workspace']['timezone']);
        $this->assertSame('owner', $data['role']);
        $this->assertCount(18, $data['permissions']);
        $this->assertSame($this->session->get('_token'), $data['csrf_token']);
        $this->assertNotSame('', $data['csrf_token']);

        $this->assertStringNotContainsString('password_hash', $response->content());
        $this->assertStringNotContainsString('auth_version', $response->content());
        $this->assertStringNotContainsString($fixture['password'], $response->content());
    }
}
