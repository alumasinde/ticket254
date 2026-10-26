<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class LoginThrottleTest extends TestCase
{
    use BuildsWorkspaces;

    public function test_sixth_attempt_for_the_same_email_is_rate_limited(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/api/v1/auth/login', [
                'email' => $fixture['email'],
                'password' => 'not-the-password',
            ], ['Accept' => 'application/json']);

            $this->assertSame(401, $response->status(), 'Attempt ' . $attempt . ' should still be checked.');
            $this->assertSame('invalid_credentials', $this->jsonBody($response)['error']['code']);
        }

        $limited = $this->post('/api/v1/auth/login', [
            'email' => $fixture['email'],
            'password' => 'not-the-password',
        ], ['Accept' => 'application/json']);

        $this->assertSame(429, $limited->status());
        $body = $this->jsonBody($limited);
        $this->assertSame('rate_limited', $body['error']['code']);
        $this->assertSame('Too many sign-in attempts. Try again shortly.', $body['error']['message']);

        $retryAfter = (int) $limited->header('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        $emailKey = $this->db()->table('rate_limits')
            ->where('limiter_key', 'login:email:' . $fixture['email'])
            ->first();
        $this->assertNotNull($emailKey);
        $this->assertSame(6, (int) $emailKey['hits']);

        $ipKey = $this->db()->table('rate_limits')
            ->where('limiter_key', 'login:ip:127.0.0.1')
            ->first();
        $this->assertNotNull($ipKey);
        $this->assertSame(6, (int) $ipKey['hits']);
    }

    public function test_the_ip_key_limits_attempts_across_different_emails(): void
    {
        $emails = [];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $email = $this->uniqueEmail('nobody');
            $emails[] = $email;
            $response = $this->post('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'not-the-password',
            ], ['Accept' => 'application/json']);

            $this->assertSame(401, $response->status(), 'Attempt ' . $attempt . ' should still be checked.');
        }

        $email = $this->uniqueEmail('nobody');
        $emails[] = $email;
        $limited = $this->post('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ], ['Accept' => 'application/json']);

        $this->assertSame(429, $limited->status());
        $this->assertSame('rate_limited', $this->jsonBody($limited)['error']['code']);

        $ipKey = $this->db()->table('rate_limits')
            ->where('limiter_key', 'login:ip:127.0.0.1')
            ->first();
        $this->assertNotNull($ipKey);
        $this->assertSame(6, (int) $ipKey['hits']);

        $rows = $this->db()->select("SELECT limiter_key, hits FROM rate_limits WHERE limiter_key LIKE 'login:email:%'");
        $this->assertCount(6, $rows);

        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row['hits']);
        }

        foreach ($emails as $attempted) {
            $this->assertContains('login:email:' . $attempted, array_column($rows, 'limiter_key'));
        }
    }

    public function test_an_expired_window_allows_the_account_to_sign_in_again(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/api/v1/auth/login', [
                'email' => $fixture['email'],
                'password' => 'not-the-password',
            ], ['Accept' => 'application/json']);
        }

        $limited = $this->post('/api/v1/auth/login', [
            'email' => $fixture['email'],
            'password' => 'not-the-password',
        ], ['Accept' => 'application/json']);
        $this->assertSame(429, $limited->status());

        $this->db()->statement(
            "UPDATE rate_limits SET expires_at = ? WHERE limiter_key LIKE 'login:%'",
            [gmdate('Y-m-d H:i:s', time() - 60)],
        );

        $response = $this->loginAs($fixture);

        $this->assertSame(200, $response->status(), $response->content());

        $ipKey = $this->db()->table('rate_limits')
            ->where('limiter_key', 'login:ip:127.0.0.1')
            ->first();
        $this->assertNotNull($ipKey);
        $this->assertSame(1, (int) $ipKey['hits']);
    }
}
