<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Repositories\CategoryRepository;
use App\Repositories\TeamRepository;
use App\Services\Tenants\TenantAccess;
use App\Services\Tenants\TenantContext;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use BuildsWorkspaces;

    /** @var array{tenant_id: int, user_id: int, name: string, slug: string, email: string, password: string} */
    private array $glee;

    /** @var array{tenant_id: int, user_id: int, name: string, slug: string, email: string, password: string} */
    private array $savannah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->glee = $this->registerWorkspaceFixture('Glee Hotel');
        $this->savannah = $this->registerWorkspaceFixture('Savannah Lodge');

        $login = $this->loginAs($this->glee);
        $this->assertSame(200, $login->status(), $login->content());
    }

    public function test_workspace_page_ignores_request_tenant_id_and_shows_only_own_rows(): void
    {
        $this->db()->table('teams')->insert(['tenant_id' => $this->glee['tenant_id'], 'name' => 'Glee Security']);
        $this->db()->table('teams')->insert(['tenant_id' => $this->savannah['tenant_id'], 'name' => 'Savannah Spa']);
        $this->db()->table('categories')->insert(['tenant_id' => $this->glee['tenant_id'], 'name' => 'Glee Concierge']);
        $this->db()->table('categories')->insert(['tenant_id' => $this->savannah['tenant_id'], 'name' => 'Savannah Excursions']);

        $response = $this->get('/app?tenant_id=' . $this->savannah['tenant_id'], [
            'X-Tenant-Id' => (string) $this->savannah['tenant_id'],
        ]);

        $this->assertSame(200, $response->status());
        $content = $response->content();

        $this->assertStringContainsString('Glee Hotel', $content);
        $this->assertStringContainsString('Glee Security', $content);
        $this->assertStringContainsString('Glee Concierge', $content);
        $this->assertStringNotContainsString('Savannah Lodge', $content);
        $this->assertStringNotContainsString('Savannah Spa', $content);
        $this->assertStringNotContainsString('Savannah Excursions', $content);

        $context = $this->app->container()->get(TenantContext::class);
        $this->assertSame($this->glee['tenant_id'], $context->tenantId);
        $this->assertSame($this->glee['user_id'], $context->userId);
    }

    public function test_me_reports_the_session_workspace_not_the_requested_one(): void
    {
        $response = $this->get('/api/v1/auth/me?tenant_id=' . $this->savannah['tenant_id'], [
            'Accept' => 'application/json',
            'X-Tenant-Id' => (string) $this->savannah['tenant_id'],
        ]);

        $this->assertSame(200, $response->status());
        $data = $this->jsonBody($response)['data'];

        $this->assertSame($this->glee['tenant_id'], $data['workspace']['id']);
        $this->assertSame('Glee Hotel', $data['workspace']['name']);
        $this->assertStringNotContainsString('Savannah Lodge', $response->content());
    }

    public function test_session_tenant_cannot_be_switched_to_a_foreign_workspace(): void
    {
        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $auth['tenant_id'] = $this->savannah['tenant_id'];
        $this->session->put('_auth', $auth);

        $response = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(403, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('forbidden', $body['error']['code']);
        $this->assertSame('You do not have access to this workspace.', $body['error']['message']);

        $this->assertNotNull($this->sessionAuth(), 'A permission failure must not silently sign the user in elsewhere.');
        $this->assertStringNotContainsString('Savannah Lodge', $response->content());
    }

    public function test_page_renders_403_when_the_session_points_at_a_foreign_workspace(): void
    {
        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $auth['tenant_id'] = $this->savannah['tenant_id'];
        $this->session->put('_auth', $auth);

        $response = $this->get('/app');

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString('Access denied', $response->content());
        $this->assertStringContainsString('You do not have access to this workspace.', $response->content());
        $this->assertStringNotContainsString('Savannah Lodge', $response->content());
    }

    public function test_team_and_category_queries_are_scoped_to_the_context_tenant(): void
    {
        $this->db()->table('teams')->insert(['tenant_id' => $this->glee['tenant_id'], 'name' => 'Glee Security']);
        $this->db()->table('teams')->insert(['tenant_id' => $this->savannah['tenant_id'], 'name' => 'Savannah Spa']);
        $this->db()->table('categories')->insert(['tenant_id' => $this->glee['tenant_id'], 'name' => 'Glee Concierge']);
        $this->db()->table('categories')->insert(['tenant_id' => $this->savannah['tenant_id'], 'name' => 'Savannah Excursions']);

        $access = $this->app->container()->get(TenantAccess::class);
        $contextA = $access->contextFor($this->glee['user_id'], $this->glee['tenant_id'], 1);
        $contextB = $access->contextFor($this->savannah['user_id'], $this->savannah['tenant_id'], 1);

        $teamRepository = $this->app->container()->get(TeamRepository::class);
        $teamsA = $teamRepository->all($contextA);
        $teamsB = $teamRepository->all($contextB);

        $this->assertCount(5, $teamsA);
        $this->assertCount(5, $teamsB);
        $this->assertContains('Glee Security', array_column($teamsA, 'name'));
        $this->assertNotContains('Savannah Spa', array_column($teamsA, 'name'));
        $this->assertContains('Savannah Spa', array_column($teamsB, 'name'));
        $this->assertNotContains('Glee Security', array_column($teamsB, 'name'));
        $this->assertSame([], array_values(array_intersect(
            array_column($teamsA, 'id'),
            array_column($teamsB, 'id'),
        )));

        $categoryRepository = $this->app->container()->get(CategoryRepository::class);
        $categoriesA = $categoryRepository->all($contextA);
        $categoriesB = $categoryRepository->all($contextB);

        $this->assertCount(7, $categoriesA);
        $this->assertCount(7, $categoriesB);
        $this->assertContains('Glee Concierge', array_column($categoriesA, 'name'));
        $this->assertNotContains('Savannah Excursions', array_column($categoriesA, 'name'));
        $this->assertContains('Savannah Excursions', array_column($categoriesB, 'name'));
        $this->assertNotContains('Glee Concierge', array_column($categoriesB, 'name'));
        $this->assertSame([], array_values(array_intersect(
            array_column($categoriesA, 'id'),
            array_column($categoriesB, 'id'),
        )));
    }

    public function test_disabled_membership_blocks_access_until_it_is_restored(): void
    {
        $this->db()->table('tenant_memberships')
            ->where('tenant_id', $this->glee['tenant_id'])
            ->where('user_id', $this->glee['user_id'])
            ->update(['status' => 'disabled']);

        $denied = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(403, $denied->status());
        $body = $this->jsonBody($denied);
        $this->assertSame('forbidden', $body['error']['code']);
        $this->assertSame('Your access to this workspace has been disabled.', $body['error']['message']);
        $this->assertNotNull($this->sessionAuth());

        $this->db()->table('tenant_memberships')
            ->where('tenant_id', $this->glee['tenant_id'])
            ->where('user_id', $this->glee['user_id'])
            ->update(['status' => 'active']);

        $allowed = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(200, $allowed->status());
        $this->assertSame($this->glee['tenant_id'], $this->jsonBody($allowed)['data']['workspace']['id']);
    }

    public function test_suspended_workspace_returns_a_neutral_tenant_suspended_error(): void
    {
        $this->db()->table('tenants')
            ->where('id', $this->glee['tenant_id'])
            ->update(['status' => 'suspended']);

        $api = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);

        $this->assertSame(403, $api->status());
        $body = $this->jsonBody($api);
        $this->assertSame('tenant_suspended', $body['error']['code']);
        $this->assertSame('This workspace is unavailable.', $body['error']['message']);

        $page = $this->get('/app');

        $this->assertSame(403, $page->status());
        $this->assertStringContainsString('This workspace is unavailable.', $page->content());
        $this->assertStringNotContainsString('Savannah Lodge', $page->content());
        $this->assertNotNull($this->sessionAuth());
    }

    public function test_disabled_user_is_signed_out_without_reaching_the_workspace(): void
    {
        $this->db()->table('users')->where('id', $this->glee['user_id'])->update(['status' => 'disabled']);

        $page = $this->get('/app');

        $this->assertSame(302, $page->status());
        $this->assertSame('/login', $page->header('Location'));
        $this->assertNull($this->sessionAuth());

        $api = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->assertSame(401, $api->status());
        $this->assertSame('unauthenticated', $this->jsonBody($api)['error']['code']);
    }

    public function test_guests_cannot_reach_private_routes(): void
    {
        $this->session->destroy();

        $page = $this->get('/app');
        $this->assertSame(302, $page->status());
        $this->assertSame('/login', $page->header('Location'));

        $me = $this->get('/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->assertSame(401, $me->status());
        $this->assertSame('unauthenticated', $this->jsonBody($me)['error']['code']);

        $logout = $this->post('/api/v1/auth/logout', [], ['Accept' => 'application/json']);
        $this->assertSame(401, $logout->status());
        $this->assertSame('unauthenticated', $this->jsonBody($logout)['error']['code']);

        $this->assertFalse($this->app->container()->hasInstance(TenantContext::class));
    }

    public function test_signing_in_as_another_account_rebuilds_the_tenant_context(): void
    {
        $first = $this->get('/app');
        $this->assertSame(200, $first->status());
        $this->assertStringContainsString('Glee Hotel', $first->content());
        $this->assertStringNotContainsString('Savannah Lodge', $first->content());

        $login = $this->loginAs($this->savannah);
        $this->assertSame(200, $login->status(), $login->content());

        $second = $this->get('/app');
        $this->assertSame(200, $second->status());
        $this->assertStringContainsString('Savannah Lodge', $second->content());
        $this->assertStringNotContainsString('Glee Hotel', $second->content());

        $context = $this->app->container()->get(TenantContext::class);
        $this->assertSame($this->savannah['tenant_id'], $context->tenantId);
        $this->assertSame($this->savannah['user_id'], $context->userId);
    }

    public function test_registration_never_attaches_to_a_request_supplied_tenant(): void
    {
        $payload = $this->registrationPayload([
            'tenant_id' => $this->savannah['tenant_id'],
            'workspace_name' => 'Lakeview Lodge',
        ]);

        $response = $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json']);

        $this->assertSame(201, $response->status(), $response->content());
        $workspace = $this->jsonBody($response)['data']['workspace'];

        $this->assertNotSame($this->glee['tenant_id'], $workspace['id']);
        $this->assertNotSame($this->savannah['tenant_id'], $workspace['id']);
        $this->assertSame('Lakeview Lodge', $workspace['name']);

        $tenant = $this->db()->table('tenants')->where('id', (int) $workspace['id'])->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Lakeview Lodge', $tenant['name']);

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame((int) $workspace['id'], $auth['tenant_id']);
    }
}
