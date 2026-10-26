<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class MembersTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    public function test_owner_can_change_roles_disable_reenable_and_remove_members(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $itTeam = $this->teamId($fixture['tenant_id'], 'IT');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->joinTeam($fixture['tenant_id'], $itTeam, $agent['user_id']);
        $authVersion = (int) $this->db()->table('users')->where('id', $agent['user_id'])->value('auth_version');

        $promoted = $this->apiPatch('/api/v1/members/' . $agent['user_id'], ['role' => 'admin']);
        $this->assertSame(200, $promoted->status(), $promoted->content());
        $this->assertSame('admin', $this->jsonBody($promoted)['data']['role']);

        $disabled = $this->apiPatch('/api/v1/members/' . $agent['user_id'], ['status' => 'disabled']);
        $this->assertSame(200, $disabled->status(), $disabled->content());
        $this->assertSame('disabled', $this->jsonBody($disabled)['data']['status']);
        $this->assertSame(
            $authVersion + 1,
            (int) $this->db()->table('users')->where('id', $agent['user_id'])->value('auth_version'),
        );

        $enabled = $this->apiPatch('/api/v1/members/' . $agent['user_id'], ['status' => 'active']);
        $this->assertSame(200, $enabled->status(), $enabled->content());
        $this->assertSame('active', $this->jsonBody($enabled)['data']['status']);

        $removed = $this->webDelete('/app/members/' . $agent['user_id']);
        $this->assertSame(302, $removed->status());

        $this->assertSame('disabled', (string) $this->db()->table('tenant_memberships')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('user_id', $agent['user_id'])
            ->value('status'));
        $this->assertSame(0, $this->db()->table('team_members')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('user_id', $agent['user_id'])
            ->count());
        $this->assertSame(
            $authVersion + 2,
            (int) $this->db()->table('users')->where('id', $agent['user_id'])->value('auth_version'),
        );

        foreach (['member.role_changed', 'member.disabled', 'member.enabled', 'member.removed'] as $action) {
            $this->assertSame(
                1,
                $this->db()->table('audit_logs')
                    ->where('tenant_id', $fixture['tenant_id'])
                    ->where('action', $action)
                    ->count(),
                'Missing audit entry [' . $action . '].',
            );
        }
    }

    public function test_owner_cannot_disable_remove_or_demote_the_last_owner(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $disableSelf = $this->apiPatch('/api/v1/members/' . $fixture['user_id'], ['status' => 'disabled']);
        $this->assertSame(422, $disableSelf->status(), $disableSelf->content());
        $this->assertArrayHasKey('status', $this->jsonBody($disableSelf)['error']['fields']);

        $removeSelf = $this->request('DELETE', '/api/v1/members/' . $fixture['user_id'], [], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
        $this->assertSame(422, $removeSelf->status(), $removeSelf->content());

        $demoteSelf = $this->apiPatch('/api/v1/members/' . $fixture['user_id'], ['role' => 'agent']);
        $this->assertSame(422, $demoteSelf->status(), $demoteSelf->content());
        $this->assertStringContainsString(
            'at least one active owner',
            (string) $this->jsonBody($demoteSelf)['error']['fields']['role'],
        );

        $role = $this->db()->selectOne(
            'SELECT r.code FROM tenant_memberships tm INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$fixture['tenant_id'], $fixture['user_id']],
        );
        $this->assertSame('owner', (string) ($role['code'] ?? ''));
    }

    public function test_admins_cannot_grant_owner_or_change_an_owner(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $admin = $this->addMember($fixture['tenant_id'], 'admin', 'Mercy', 'Wanjiku');
        $this->loginAs($admin);

        $inviteOwner = $this->apiPost('/api/v1/invitations', [
            'email' => $this->uniqueEmail('owner'),
            'role' => 'owner',
        ]);
        $this->assertSame(422, $inviteOwner->status(), $inviteOwner->content());
        $this->assertArrayHasKey('role', $this->jsonBody($inviteOwner)['error']['fields']);

        $demoteOwner = $this->apiPatch('/api/v1/members/' . $fixture['user_id'], ['role' => 'agent']);
        $this->assertSame(403, $demoteOwner->status(), $demoteOwner->content());

        $page = $this->get('/app/members');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Mercy Wanjiku', $page->content());
        $this->assertStringContainsString('Amina Otieno', $page->content());
    }

    public function test_owner_invitation_link_and_pending_rows_render_on_the_members_page(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $email = $this->uniqueEmail('wanjiru');

        $invited = $this->webPost('/app/invitations', [
            'email' => $email,
            'role' => 'agent',
        ]);
        $this->assertSame(302, $invited->status());

        $acceptUrl = (string) $this->session->get('invitation_url');
        $this->assertMatchesRegularExpression('#/invite/[0-9a-f]{64}$#', (string) parse_url($acceptUrl, PHP_URL_PATH));

        $page = $this->get('/app/members');
        $this->assertSame(200, $page->status());
        $content = $page->content();

        $this->assertStringContainsString('Invitation link — shown once', $content);
        $this->assertStringContainsString($email, $content);
        $this->assertStringContainsString('Pending', $content);
        $this->assertStringContainsString('Invite', $content);
    }

    public function test_agents_cannot_open_or_call_the_members_directory(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $this->loginAs($agent);

        $page = $this->get('/app/members');
        $this->assertSame(403, $page->status());

        $api = $this->get('/api/v1/members', ['Accept' => 'application/json']);
        $this->assertSame(403, $api->status(), $api->content());

        $invite = $this->apiPost('/api/v1/invitations', [
            'email' => $this->uniqueEmail('outsider'),
            'role' => 'agent',
        ]);
        $this->assertSame(403, $invite->status(), $invite->content());
    }
}
