<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Records one hit against every key and returns the seconds to wait when any
     * key is over its limit, or null when the attempt is allowed.
     *
     * @param list<string> $keys
     */
    public function attempt(array $keys, int $maxAttempts, int $decaySeconds): ?int
    {
        $retryAfter = null;

        foreach ($keys as $key) {
            $retry = $this->hit($key, $maxAttempts, $decaySeconds);

            if ($retry !== null) {
                $retryAfter = max($retryAfter ?? 0, $retry);
            }
        }

        return $retryAfter;
    }

    private function hit(string $key, int $maxAttempts, int $decaySeconds): ?int
    {
        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $expiresSql = gmdate('Y-m-d H:i:s', $now + max(1, $decaySeconds));

        $this->db->statement(
            'INSERT INTO rate_limits (limiter_key, hits, window_started_at, expires_at) '
            . 'VALUES (?, 1, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'hits = IF(expires_at <= ?, 1, hits + 1), '
            . 'window_started_at = IF(expires_at <= ?, ?, window_started_at), '
            . 'expires_at = IF(expires_at <= ?, ?, expires_at)',
            [$key, $nowSql, $expiresSql, $nowSql, $nowSql, $nowSql, $nowSql, $expiresSql],
        );

        $row = $this->db->selectOne(
            'SELECT hits, expires_at FROM rate_limits WHERE limiter_key = ?',
            [$key],
        );

        if ($row === null) {
            return null;
        }

        if ((int) $row['hits'] <= $maxAttempts) {
            return null;
        }

        $expiresAt = strtotime((string) $row['expires_at'] . ' UTC');

        return $expiresAt === false ? max(1, $decaySeconds) : max(1, $expiresAt - $now);
    }
}
