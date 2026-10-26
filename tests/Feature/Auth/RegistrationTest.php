<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_api_registration_creates_the_user_workspace_membership_counter_and_template(): void
    {
        $result = $this->registerViaApi([
            'first_name' => 'Wanjiru',
            'last_name' => 'Kamau',
            'workspace_name' => 'Savannah Lodge',
        ]);

        $payload = $result['payload'];
        $data = $result['body']['data'];
        $userId = (int) $data['user']['id'];
        $tenantId = (int) $data['workspace']['id'];

        $this->assertSame('owner', $data['role']);
        $this->assertSame('Wanjiru', $data['user']['first_name']);
        $this->assertSame($payload['email'], $data['user']['email']);
        $this->assertSame('Savannah Lodge', $data['workspace']['name']);
        $this->assertSame(
            'http://localhost/request/' . $payload['slug'],
            $data['workspace']['public_form_url'],
        );

        $user = $this->db()->table('users')->where('id', $userId)->first();
        $this->assertNotNull($user);
        $this->assertSame('active', $user['status']);
        $this->assertSame(1, (int) $user['auth_version']);
        $this->assertNotSame($payload['password'], $user['password_hash']);
        $this->assertTrue(password_verify($payload['password'], (string) $user['password_hash']));

        $tenant = $this->db()->table('tenants')->where('id', $tenantId)->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Savannah Lodge', $tenant['name']);
        $this->assertSame($payload['slug'], $tenant['slug']);
        $this->assertSame('Africa/Nairobi', $tenant['timezone']);
        $this->assertSame('active', $tenant['status']);
        $this->assertSame(32, strlen((string) $tenant['public_key']));
        $this->assertSame($payload['email'], $tenant['contact_email']);

        $membership = $this->db()->selectOne(
            'SELECT tm.status, r.code FROM tenant_memberships tm '
            . 'INNER JOIN roles r ON r.id = tm.role_id '
            . 'WHERE tm.tenant_id = ? AND tm.user_id = ?',
            [$tenantId, $userId],
        );
        $this->assertNotNull($membership);
        $this->assertSame('active', $membership['status']);
        $this->assertSame('owner', $membership['code']);

        $counter = $this->db()->table('ticket_counters')->where('tenant_id', $tenantId)->first();
        $this->assertNotNull($counter);
        $this->assertSame(0, (int) $counter['last_number']);

        $teams = $this->db()->select(
            'SELECT name FROM teams WHERE tenant_id = ? ORDER BY name',
            [$tenantId],
        );
        $this->assertSame(
            ['Engineering', 'Front Office', 'Housekeeping', 'IT'],
            array_column($teams, 'name'),
        );

        $categories = $this->db()->select(
            'SELECT c.name, t.name AS team_name FROM categories c '
            . 'LEFT JOIN teams t ON t.tenant_id = c.tenant_id AND t.id = c.team_id '
            . 'WHERE c.tenant_id = ?',
            [$tenantId],
        );
        $this->assertCount(6, $categories);

        $routing = [];

        foreach ($categories as $category) {
            $routing[(string) $category['name']] = $category['team_name'];
        }

        $this->assertSame('IT', $routing['Wi-Fi/Network']);
        $this->assertSame('IT', $routing['Computer/Printer']);
        $this->assertSame('Engineering', $routing['Room maintenance']);
        $this->assertSame('Housekeeping', $routing['Housekeeping']);
        $this->assertSame('IT', $routing['POS/Systems']);
        $this->assertNull($routing['Other']);

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame($userId, $auth['user_id']);
        $this->assertSame($tenantId, $auth['tenant_id']);
        $this->assertSame(1, $auth['auth_version']);
        $this->assertSame(1, $this->session->regenerations);
        $this->assertNull($this->session->get('_token'));
    }

    public function test_registration_normalizes_input_and_defaults_the_timezone(): void
    {
        $result = $this->registerViaApi([
            'first_name' => '  Wanjiru ',
            'last_name' => ' Kamau  ',
            'email' => 'Wanjiru.Kamau@Example.CO.KE',
            'workspace_name' => '  Savannah Lodge  ',
            'timezone' => '',
        ]);

        $data = $result['body']['data'];
        $tenant = $this->db()->table('tenants')->where('id', (int) $data['workspace']['id'])->first();

        $this->assertSame('Wanjiru', $data['user']['first_name']);
        $this->assertSame('Kamau', $data['user']['last_name']);
        $this->assertSame('wanjiru.kamau@example.co.ke', $data['user']['email']);
        $this->assertSame('Savannah Lodge', $data['workspace']['name']);
        $this->assertSame('Africa/Nairobi', $data['workspace']['timezone']);
        $this->assertNotNull($tenant);
        $this->assertSame('Africa/Nairobi', $tenant['timezone']);
        $this->assertSame('wanjiru.kamau@example.co.ke', $tenant['contact_email']);
    }

    public function test_duplicate_email_is_reported_on_the_email_field_and_creates_no_tenant(): void
    {
        $first = $this->registerViaApi();
        $payload = $this->registrationPayload(['email' => $first['payload']['email']]);

        $response = $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('An account with this email already exists.', $body['error']['fields']['email']);
        $this->assertNull($this->db()->table('tenants')->where('slug', $payload['slug'])->first());

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame((int) $first['body']['data']['user']['id'], $auth['user_id']);
    }

    public function test_duplicate_slug_rolls_back_the_user_inserted_before_it(): void
    {
        $first = $this->registerViaApi();
        $payload = $this->registrationPayload(['slug' => $first['payload']['slug']]);

        $response = $this->post('/api/v1/auth/register', $payload, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('That workspace address is already taken.', $body['error']['fields']['slug']);

        $this->assertSame(
            0,
            $this->db()->table('users')->where('email', $payload['email'])->count(),
            'The user inserted before the failing tenant insert must be rolled back.',
        );
        $this->assertSame(1, $this->db()->table('tenants')->where('slug', $payload['slug'])->count());
    }

    public function test_reserved_and_malformed_slugs_are_rejected(): void
    {
        $reserved = $this->registrationPayload(['slug' => 'admin']);
        $response = $this->post('/api/v1/auth/register', $reserved, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            'That workspace address is reserved.',
            $this->jsonBody($response)['error']['fields']['slug'],
        );

        $malformed = $this->registrationPayload(['slug' => 'Bad Slug!']);
        $response = $this->post('/api/v1/auth/register', $malformed, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            'Use lowercase letters, numbers and hyphens only.',
            $this->jsonBody($response)['error']['fields']['slug'],
        );
        $this->assertNull($this->sessionAuth());
    }

    public function test_weak_password_and_invalid_timezone_are_rejected(): void
    {
        $weak = $this->registrationPayload(['password' => 'short']);
        $response = $this->post('/api/v1/auth/register', $weak, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            'Use at least 10 characters for your password.',
            $this->jsonBody($response)['error']['fields']['password'],
        );

        $badTimezone = $this->registrationPayload(['timezone' => 'Mars/Olympus']);
        $response = $this->post('/api/v1/auth/register', $badTimezone, ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            'Choose a valid timezone.',
            $this->jsonBody($response)['error']['fields']['timezone'],
        );
    }

    public function test_page_registration_requires_csrf_and_signs_the_owner_in(): void
    {
        $page = $this->get('/register');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Create your workspace', $page->content());

        $token = (string) $this->session->get('_token');
        $this->assertNotSame('', $token);

        $payload = $this->registrationPayload(['workspace_name' => 'Glee Hotel']);
        $response = $this->post('/register', $payload + ['_token' => $token], [
            'Referer' => 'http://localhost/register',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/app', $response->header('Location'));

        $app = $this->get('/app');
        $this->assertSame(200, $app->status());
        $content = $app->content();
        $this->assertStringContainsString('Glee Hotel', $content);
        $this->assertStringContainsString('Your workspace is ready.', $content);
        $this->assertStringContainsString('Today at Glee Hotel', $content);
        $this->assertStringContainsString(route('teams.index'), $content);
        $this->assertStringContainsString(route('categories.index'), $content);

        $auth = $this->sessionAuth();
        $this->assertNotNull($auth);
        $this->assertSame((int) $this->db()->table('users')->where('email', $payload['email'])->value('id'), $auth['user_id']);
    }

    public function test_page_registration_without_a_csrf_token_is_rejected_before_writing(): void
    {
        $this->get('/register');
        $payload = $this->registrationPayload();

        $response = $this->post('/register', $payload, ['Referer' => 'http://localhost/register']);

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString('Your session token expired', $response->content());
        $this->assertNull($this->sessionAuth());
        $this->assertSame(0, $this->db()->table('users')->where('email', $payload['email'])->count());
    }

    public function test_page_registration_returns_errors_and_old_input(): void
    {
        $this->get('/register');
        $token = (string) $this->session->get('_token');
        $payload = $this->registrationPayload(['password' => 'short']);

        $response = $this->post('/register', $payload + ['_token' => $token], [
            'Referer' => 'http://localhost/register',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/register', $response->header('Location'));

        $errors = $this->session->get('errors');
        $this->assertIsArray($errors);
        $this->assertSame('Use at least 10 characters for your password.', $errors['password']);

        $old = $this->session->get('_old_input');
        $this->assertIsArray($old);
        $this->assertSame($payload['email'], $old['email']);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('_token', $old);

        $page = $this->get('/register');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Use at least 10 characters for your password.', $page->content());
        $this->assertStringContainsString($payload['email'], $page->content());
    }

    public function test_signed_in_users_are_redirected_away_from_the_registration_page(): void
    {
        $this->registerViaApi();

        $response = $this->get('/register');

        $this->assertSame(302, $response->status());
        $this->assertSame('/app', $response->header('Location'));
    }
}
