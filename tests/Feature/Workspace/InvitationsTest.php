<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Mail\MailTemplates;
use App\Mail\Notification;
use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\Support\SendsWorkspaceRequests;
use Tests\TestCase;

final class InvitationsTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;
    use SendsWorkspaceRequests;

    /**
     * @param array<string, mixed> $overrides
     * @return array{id: int, token: string, email: string, data: array<string, mixed>}
     */
    private function invite(array $overrides = []): array
    {
        $email = (string) ($overrides['email'] ?? $this->uniqueEmail('invitee'));
        $response = $this->apiPost('/api/v1/invitations', array_merge([
            'email' => $email,
            'role' => 'agent',
        ], $overrides));

        $this->assertSame(201, $response->status(), $response->content());
        $data = $this->jsonBody($response)['data'];
        $token = (string) basename((string) parse_url((string) $data['accept_url'], PHP_URL_PATH));

        return [
            'id' => (int) $data['id'],
            'token' => $token,
            'email' => $email,
            'data' => $data,
        ];
    }

    public function test_owner_invites_a_new_person_who_accepts_and_joins_the_workspace(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $itTeam = $this->teamId($fixture['tenant_id'], 'IT');

        $invitation = $this->invite([
            'role' => 'admin',
            'team_ids' => [$itTeam],
        ]);

        $this->assertMatchesRegularExpression('#^/invite/[0-9a-f]{64}$#', (string) parse_url(
            (string) $invitation['data']['accept_url'],
            PHP_URL_PATH,
        ));
        $this->assertSame('pending', $invitation['data']['status']);
        $this->assertSame('admin', $invitation['data']['role']);
        $this->assertSame(['IT'], $invitation['data']['teams']);

        $row = $this->db()->table('invitations')->where('id', $invitation['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $invitation['token']), (string) $row['token_hash']);
        $this->assertSame([$itTeam], json_decode((string) $row['team_ids'], true));
        $this->assertNull($row['accepted_at']);

        $seconds = strtotime((string) $row['expires_at'] . ' UTC') - time();
        $this->assertGreaterThan(6.9 * 86400, $seconds);
        $this->assertLessThan(7.1 * 86400, $seconds);

        $outbox = $this->db()->table('notification_outbox')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('event_type', 'invitation')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame($invitation['email'], (string) $outbox['recipient_email']);
        $payload = json_decode((string) $outbox['payload'], true);
        $this->assertSame('Glee Hotel', $payload['workspace_name']);
        $this->assertSame('Admin', $payload['role']);
        $this->assertSame($invitation['data']['accept_url'], $payload['accept_url']);

        $this->assertSame(1, $this->db()->table('audit_logs')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('action', 'invitation.sent')
            ->count());

        $signedInPage = $this->get('/invite/' . $invitation['token']);
        $this->assertSame(200, $signedInPage->status());
        $this->assertStringContainsString('Sign out', $signedInPage->content());

        $this->session->destroy();

        $page = $this->get('/invite/' . $invitation['token']);
        $this->assertSame(200, $page->status());
        $content = $page->content();
        $this->assertStringContainsString('Join Glee Hotel', $content);
        $this->assertStringContainsString($invitation['email'], $content);
        $this->assertStringContainsString('name="first_name"', $content);
        $this->assertStringContainsString('name="password"', $content);

        $accepted = $this->post('/api/v1/invitations/accept', [
            'token' => $invitation['token'],
            'first_name' => 'Wanjiru',
            'last_name' => 'Kamau',
            'password' => 'a-long-passphrase',
            'password_confirmation' => 'a-long-passphrase',
        ], ['Accept' => 'application/json']);

        $this->assertSame(200, $accepted->status(), $accepted->content());
        $acceptedData = $this->jsonBody($accepted)['data'];
        $this->assertSame('Glee Hotel', $acceptedData['workspace_name']);
        $this->assertSame($invitation['email'], $acceptedData['email']);

        $user = $this->db()->table('users')->where('email', $invitation['email'])->first();
        $this->assertNotNull($user);

        $membership = $this->db()->selectOne(
            'SELECT tm.status, r.code FROM tenant_memberships tm INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$fixture['tenant_id'], (int) $user['id']],
        );
        $this->assertSame('active', (string) $membership['status']);
        $this->assertSame('admin', (string) $membership['code']);

        $this->assertSame(1, $this->db()->table('team_members')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('team_id', $itTeam)
            ->where('user_id', (int) $user['id'])
            ->count());

        $this->assertNotNull($this->db()->table('invitations')->where('id', $invitation['id'])->value('accepted_at'));

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame((int) $user['id'], $auth['user_id']);
        $this->assertSame($fixture['tenant_id'], $auth['tenant_id']);

        $again = $this->post('/api/v1/invitations/accept', [
            'token' => $invitation['token'],
            'first_name' => 'Wanjiru',
            'last_name' => 'Kamau',
            'password' => 'a-long-passphrase',
        ], ['Accept' => 'application/json']);
        $this->assertSame(410, $again->status(), $again->content());
    }

    public function test_expired_or_revoked_invitations_cannot_be_opened_or_accepted(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        $expired = $this->invite();
        $this->db()->table('invitations')
            ->where('id', $expired['id'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->assertSame(410, $this->get('/invite/' . $expired['token'])->status());

        $this->session->destroy();

        $this->assertSame(410, $this->post('/api/v1/invitations/accept', [
            'token' => $expired['token'],
            'first_name' => 'Late',
            'last_name' => 'Guest',
            'password' => 'a-long-passphrase',
        ], ['Accept' => 'application/json'])->status());

        $this->loginAs($fixture);

        $revoked = $this->invite();
        $revoke = $this->apiDelete('/api/v1/invitations/' . $revoked['id']);
        $this->assertSame(200, $revoke->status(), $revoke->content());
        $this->assertSame('revoked', $this->jsonBody($revoke)['data']['status']);

        $this->assertSame(410, $this->get('/invite/' . $revoked['token'])->status());

        $this->session->destroy();

        $this->assertSame(410, $this->post('/api/v1/invitations/accept', [
            'token' => $revoked['token'],
            'first_name' => 'Late',
            'last_name' => 'Guest',
            'password' => 'a-long-passphrase',
        ], ['Accept' => 'application/json'])->status());
    }

    public function test_invitation_rules_reject_duplicates_existing_members_and_owner_escalation(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $agent = $this->addMember($fixture['tenant_id'], 'agent', 'Brian', 'Kiptoo');
        $admin = $this->addMember($fixture['tenant_id'], 'admin', 'Mercy', 'Wanjiku');

        $this->loginAs($agent);
        $blocked = $this->apiPost('/api/v1/invitations', [
            'email' => $this->uniqueEmail('agent-invite'),
            'role' => 'agent',
        ]);
        $this->assertSame(403, $blocked->status(), $blocked->content());

        $this->loginAs($admin);
        $escalation = $this->apiPost('/api/v1/invitations', [
            'email' => $this->uniqueEmail('owner'),
            'role' => 'owner',
        ]);
        $this->assertSame(422, $escalation->status(), $escalation->content());
        $this->assertArrayHasKey('role', $this->jsonBody($escalation)['error']['fields']);

        $this->loginAs($fixture);

        $email = $this->uniqueEmail('pending');
        $this->invite(['email' => $email]);

        $duplicate = $this->apiPost('/api/v1/invitations', ['email' => $email, 'role' => 'agent']);
        $this->assertSame(422, $duplicate->status(), $duplicate->content());
        $this->assertStringContainsString(
            'already pending',
            (string) $this->jsonBody($duplicate)['error']['fields']['email'],
        );

        $member = $this->apiPost('/api/v1/invitations', [
            'email' => $fixture['email'],
            'role' => 'agent',
        ]);
        $this->assertSame(422, $member->status(), $member->content());

        $invalidRole = $this->apiPost('/api/v1/invitations', [
            'email' => $this->uniqueEmail('role'),
            'role' => 'superuser',
        ]);
        $this->assertSame(422, $invalidRole->status(), $invalidRole->content());

        $this->assertSame(1, $this->db()->table('invitations')
            ->where('tenant_id', $fixture['tenant_id'])
            ->where('email', $email)
            ->count());
    }

    public function test_an_existing_user_accepts_an_invitation_and_switches_workspace(): void
    {
        $glee = $this->registerWorkspaceFixture('Glee Hotel');
        $savannah = $this->registerWorkspaceFixture('Savannah Lodge');

        $this->loginAs($glee);
        $invitation = $this->invite([
            'email' => $savannah['email'],
            'role' => 'agent',
        ]);

        $this->loginAs($savannah);

        $page = $this->get('/invite/' . $invitation['token']);
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Accept invitation', $page->content());

        $accepted = $this->post('/api/v1/invitations/accept', [
            'token' => $invitation['token'],
        ], ['Accept' => 'application/json']);

        $this->assertSame(200, $accepted->status(), $accepted->content());
        $this->assertSame('Glee Hotel', $this->jsonBody($accepted)['data']['workspace_name']);

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame($savannah['user_id'], $auth['user_id']);
        $this->assertSame($glee['tenant_id'], $auth['tenant_id']);

        $membership = $this->db()->selectOne(
            'SELECT tm.status, r.code FROM tenant_memberships tm INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$glee['tenant_id'], $savannah['user_id']],
        );
        $this->assertSame('active', (string) $membership['status']);
        $this->assertSame('agent', (string) $membership['code']);
    }

    public function test_invitation_mail_template_renders_the_accept_link_and_context(): void
    {
        $url = 'http://localhost/invite/' . str_repeat('a', 64);
        $notification = new Notification(
            1,
            null,
            'invitation',
            'wanjiru@example.co.ke',
            null,
            [
                'workspace_name' => 'Glee Hotel',
                'role' => 'Admin',
                'inviter_name' => 'Amina Otieno',
                'teams' => 'IT, Front Office',
                'accept_url' => $url,
                'expires_at' => '2 Nov 2026',
            ],
        );

        $message = $this->app->container()->get(MailTemplates::class)->render($notification);

        $this->assertSame('You are invited to Glee Hotel', $message->subject);
        $this->assertStringContainsString($url, $message->text);
        $this->assertStringContainsString($url, $message->html);
        $this->assertStringContainsString('Admin', $message->text);
        $this->assertStringContainsString('Amina Otieno', $message->text);
        $this->assertStringContainsString('IT, Front Office', $message->text);
    }
}
