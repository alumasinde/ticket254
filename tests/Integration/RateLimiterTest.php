<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\RateLimiter;
use Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    public function test_attempt_allows_up_to_the_limit_then_returns_retry_seconds(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->container()->get(RateLimiter::class);
        $key = 'probe:' . bin2hex(random_bytes(6));

        $this->assertNull($limiter->attempt([$key], 2, 60));
        $this->assertNull($limiter->attempt([$key], 2, 60));

        $retryAfter = $limiter->attempt([$key], 2, 60);

        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        $row = $this->db()->table('rate_limits')->where('limiter_key', $key)->first();
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['hits']);
    }

    public function test_attempt_increments_every_key(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->container()->get(RateLimiter::class);
        $suffix = bin2hex(random_bytes(6));
        $keys = ['probe:ip:' . $suffix, 'probe:email:' . $suffix];

        $this->assertNull($limiter->attempt($keys, 5, 60));

        foreach ($keys as $key) {
            $row = $this->db()->table('rate_limits')->where('limiter_key', $key)->first();
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row['hits']);
        }
    }

    public function test_an_expired_window_resets_the_counter(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->container()->get(RateLimiter::class);
        $key = 'probe:' . bin2hex(random_bytes(6));

        $limiter->attempt([$key], 1, 60);
        $this->assertNotNull($limiter->attempt([$key], 1, 60));

        $this->db()->statement(
            'UPDATE rate_limits SET expires_at = ? WHERE limiter_key = ?',
            [gmdate('Y-m-d H:i:s', time() - 60), $key],
        );

        $this->assertNull($limiter->attempt([$key], 1, 60));

        $row = $this->db()->table('rate_limits')->where('limiter_key', $key)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['hits']);
    }
}
