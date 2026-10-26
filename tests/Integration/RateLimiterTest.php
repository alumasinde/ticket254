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

    public function test_prune_removes_only_windows_past_the_grace_period(): void
    {
        /** @var RateLimiter $limiter */
        $limiter = $this->app->container()->get(RateLimiter::class);
        $suffix = bin2hex(random_bytes(6));
        $old = 'prune:old:' . $suffix;
        $recent = 'prune:recent:' . $suffix;
        $active = 'prune:active:' . $suffix;

        $this->db()->table('rate_limits')->insert([
            'limiter_key' => $old,
            'hits' => 3,
            'window_started_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400),
        ]);
        $this->db()->table('rate_limits')->insert([
            'limiter_key' => $recent,
            'hits' => 1,
            'window_started_at' => gmdate('Y-m-d H:i:s', time() - 7200),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        $this->db()->table('rate_limits')->insert([
            'limiter_key' => $active,
            'hits' => 1,
            'window_started_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->assertSame(1, $limiter->prune(1));
        $this->assertNull($this->db()->table('rate_limits')->where('limiter_key', $old)->first());
        $this->assertNotNull($this->db()->table('rate_limits')->where('limiter_key', $recent)->first());
        $this->assertNotNull($this->db()->table('rate_limits')->where('limiter_key', $active)->first());
    }
}
