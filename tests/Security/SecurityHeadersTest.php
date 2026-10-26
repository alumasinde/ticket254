<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_public_and_private_pages_send_hardening_headers(): void
    {
        foreach (['/', '/login', '/app'] as $path) {
            $response = $this->get($path);

            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'), $path);
            $this->assertSame('DENY', $response->header('X-Frame-Options'), $path);
            $this->assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'), $path);
            $this->assertStringContainsString('camera=()', (string) $response->header('Permissions-Policy'), $path);
            $this->assertSame('same-origin', $response->header('Cross-Origin-Opener-Policy'), $path);

            $csp = (string) $response->header('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp, $path);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp, $path);
            $this->assertStringContainsString("object-src 'none'", $csp, $path);
            $this->assertStringContainsString("img-src 'self' data:", $csp, $path);
            $this->assertStringContainsString("form-action 'self'", $csp, $path);
            $this->assertNull($response->header('Strict-Transport-Security'), $path);
        }
    }

    public function test_error_and_api_responses_are_hardened_too(): void
    {
        $api = $this->get('/api/v1/does-not-exist', ['Accept' => 'application/json']);
        $this->assertSame(404, $api->status());

        $page = $this->get('/does-not-exist');
        $this->assertSame(404, $page->status());

        foreach ([$api, $page] as $response) {
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->header('X-Frame-Options'));
            $this->assertStringContainsString(
                "frame-ancestors 'none'",
                (string) $response->header('Content-Security-Policy'),
            );
        }
    }
}
