<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class DirectoryTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    public function test_teams_can_be_created_staffed_archived_and_restored_with_audit_trail(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');

        $created = $this->webPost('/app/teams', ['name' => 'Kitchen']);
        $this->assertSame(302, $created->status());
        $this->assertSame('/app/teams', $created->header('Location'));

        $team = $this->db()->table('teams')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('name', 'Kitchen')
            ->first();
        $this->assertNotNull($team);
        $this->assertSame('active', (string) $team['status']);
        $teamId = (int) $team['id'];

        $this->assertSame(302, $this->webPost('/app/teams/' . $teamId . '/members', [
            'user_id' => $agent['user_id'],
        ])->status());

        $list = $this->jsonBody($this->get('/api/v1/teams', ['Accept' => 'application/json']))['data'];
        $listed = array_values(array_filter($list, static fn (array $row): bool => $row['id'] === $teamId))[0];

        $this->assertSame(1, $listed['member_count']);
        $this->assertSame([['id' => $agent['user_id'], 'name' => 'Brian Kiptoo']], $listed['members']);

        $this->assertSame(302, $this->webDelete('/app/teams/' . $teamId . '/members/' . $agent['user_id'])->status());
        $this->assertSame(0, $this->db()->table('team_members')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('team_id', $teamId)
            ->count());

        $this->assertSame(302, $this->webDelete('/app/teams/' . $teamId)->status());
        $this->assertSame('archived', (string) $this->db()->table('teams')->where('id', $teamId)->value('status'));

        $this->assertSame(302, $this->webPatch('/app/teams/' . $teamId, ['status' => 'active'])->status());
        $this->assertSame('active', (string) $this->db()->table('teams')->where('id', $teamId)->value('status'));

        foreach (['team.created', 'team.member_added', 'team.member_removed', 'team.archived', 'team.updated'] as $action) {
            $this->assertSame(
                1,
                $this->db()->table('audit_logs')
                    ->where('tenant_id', $fixture['tenant_id'])
                    ->where('action', $action)
                    ->count(),
                'Missing audit entry [' . $action . '].',
            );
        }

        $page = $this->get('/app/teams');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Kitchen', $page->content());
        $this->assertStringContainsString('No members yet', $page->content());
    }

    public function test_categories_and_sites_crud_are_dynamic_and_archivable(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $teamId = $this->teamId($fixture['tenant_id'], 'IT');

        $site = $this->apiPost('/api/v1/sites', [
            'name' => 'Nairobi CBD',
            'address' => 'Kenyatta Avenue',
        ]);
        $this->assertSame(201, $site->status(), $site->content());
        $siteData = $this->jsonBody($site)['data'];
        $this->assertSame('active', $siteData['status']);
        $this->assertSame('Kenyatta Avenue', $siteData['address']);

        $category = $this->apiPost('/api/v1/categories', [
            'name' => 'Laundry',
            'team_id' => $teamId,
            'default_priority' => 'high',
        ]);
        $this->assertSame(201, $category->status(), $category->content());
        $categoryData = $this->jsonBody($category)['data'];
        $this->assertSame('high', $categoryData['default_priority']);
        $this->assertSame('IT', $categoryData['team']['name']);

        $updated = $this->apiPatch('/api/v1/categories/' . $categoryData['id'], [
            'name' => 'Laundry & Linen',
            'default_priority' => 'urgent',
        ]);
        $this->assertSame(200, $updated->status(), $updated->content());
        $this->assertSame('Laundry & Linen', $this->jsonBody($updated)['data']['name']);
        $this->assertSame('urgent', $this->jsonBody($updated)['data']['default_priority']);

        $archivedSite = $this->apiDelete('/api/v1/sites/' . $siteData['id']);
        $this->assertSame(200, $archivedSite->status(), $archivedSite->content());
        $this->assertSame('archived', $this->jsonBody($archivedSite)['data']['status']);

        $archivedCategory = $this->apiDelete('/api/v1/categories/' . $categoryData['id']);
        $this->assertSame(200, $archivedCategory->status(), $archivedCategory->content());
        $this->assertSame('archived', $this->jsonBody($archivedCategory)['data']['status']);

        foreach (['site.created', 'category.created', 'category.updated', 'site.archived', 'category.archived'] as $action) {
            $this->assertSame(
                1,
                $this->db()->table('audit_logs')
                    ->where('tenant_id', $fixture['tenant_id'])
                    ->where('action', $action)
                    ->count(),
                'Missing audit entry [' . $action . '].',
            );
        }

        $page = $this->get('/app/categories');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Laundry &amp; Linen', $page->content());
        $this->assertStringContainsString('Nairobi CBD', $page->content());
    }

    public function test_teams_from_another_workspace_cannot_be_changed(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');
        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');
        $foreignTeam = $this->teamId($savannah['tenant_id'], 'Front Office');

        $this->loginAs($glee);

        $update = $this->request('PATCH', '/api/v1/teams/' . $foreignTeam, ['name' => 'Hijacked'], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
        $this->assertSame(404, $update->status(), $update->content());

        $delete = $this->apiDelete('/api/v1/teams/' . $foreignTeam);
        $this->assertSame(404, $delete->status(), $delete->content());

        $this->assertSame(
            'Front Office',
            (string) $this->db()->table('teams')->where('id', $foreignTeam)->value('name'),
        );
        $this->assertSame(
            'active',
            (string) $this->db()->table('teams')->where('id', $foreignTeam)->value('status'),
        );

        $list = $this->jsonBody($this->get('/api/v1/teams', ['Accept' => 'application/json']))['data'];
        $this->assertNotContains($foreignTeam, array_map('intval', array_column($list, 'id')));
    }

    public function test_agents_cannot_manage_teams_or_categories(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->loginAs($agent);

        $page = $this->get('/app/teams');
        $this->assertSame(403, $page->status());
        $this->assertStringContainsString('Access denied', $page->content());

        $teams = $this->apiPost('/api/v1/teams', ['name' => 'Side Door']);
        $this->assertSame(403, $teams->status(), $teams->content());

        $categories = $this->apiPost('/api/v1/categories', ['name' => 'Side Work']);
        $this->assertSame(403, $categories->status(), $categories->content());

        $this->assertSame(0, $this->db()->table('teams')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('name', 'Side Door')
            ->count());
    }

    public function test_directory_writes_require_a_csrf_token(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $api = $this->post('/api/v1/teams', ['name' => 'No Token'], ['Accept' => 'application/json']);
        $this->assertSame(403, $api->status(), $api->content());

        $web = $this->post('/app/teams', ['name' => 'No Token']);
        $this->assertSame(403, $web->status(), $web->content());

        $this->assertSame(0, $this->db()->table('teams')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('name', 'No Token')
            ->count());
    }
}
