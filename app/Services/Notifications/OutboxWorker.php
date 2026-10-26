<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Database\Database;
use App\Mail\DeliveryResult;
use App\Mail\Notification;
use App\Mail\NotificationChannel;
use App\Support\RateLimiter;
use Psr\Log\LoggerInterface;

final class OutboxWorker
{
    private const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    private const MAX_ATTEMPTS = 6;

    private const STALE_LOCK_SECONDS = 600;

    public function __construct(
        private readonly Database $db,
        private readonly NotificationChannel $channel,
        private readonly LoggerInterface $logger,
        private readonly RateLimiter $rateLimits,
    ) {
    }

    /**
     * @return array{claimed: int, sent: int, failed: int}
     */
    public function work(int $limit = 25): array
    {
        $this->recoverStale();
        $this->cleanupRateLimits();

        $claimed = 0;
        $sent = 0;
        $failed = 0;

        while ($claimed < $limit) {
            $row = $this->claim();

            if ($row === null) {
                break;
            }

            $claimed++;
            $result = $this->deliver($row);

            if ($result->ok) {
                $sent++;
                $this->recordSuccess((int) $row['id']);

                continue;
            }

            $failed++;
            $this->recordFailure($row, (string) $result->error);
            $this->logger->warning('Outbox delivery failed', [
                'outbox_id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'attempts' => (int) $row['attempts'] + 1,
                'error' => $result->error,
            ]);
        }

        return ['claimed' => $claimed, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        return $this->db->transaction(function (): ?array {
            $row = $this->db->selectOne(
                'SELECT * FROM notification_outbox '
                . 'WHERE status = ? AND available_at <= UTC_TIMESTAMP() '
                . 'ORDER BY available_at ASC, id ASC LIMIT 1 FOR UPDATE',
                ['pending'],
            );

            if ($row === null) {
                return null;
            }

            $this->db->statement(
                'UPDATE notification_outbox SET status = ?, locked_at = UTC_TIMESTAMP() '
                . 'WHERE id = ? AND status = ?',
                ['sending', (int) $row['id'], 'pending'],
            );

            $row['status'] = 'sending';

            return $row;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function deliver(array $row): DeliveryResult
    {
        $payload = $row['payload'] ?? [];
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return $this->channel->send(new Notification(
            (int) ($row['tenant_id'] ?? 0),
            $row['ticket_id'] === null ? null : (int) $row['ticket_id'],
            (string) $row['event_type'],
            (string) $row['recipient_email'],
            $row['recipient_name'] === null ? null : (string) $row['recipient_name'],
            is_array($decoded) ? $decoded : [],
        ));
    }

    private function recordSuccess(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->statement(
                'UPDATE notification_outbox SET status = ?, attempts = attempts + 1, sent_at = UTC_TIMESTAMP(), '
                . 'locked_at = NULL, last_error = NULL WHERE id = ?',
                ['sent', $id],
            );

            $this->db->table('notification_attempts')->insert([
                'outbox_id' => $id,
                'success' => 1,
                'error' => null,
                'attempted_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordFailure(array $row, string $error): void
    {
        $id = (int) $row['id'];
        $attempts = (int) $row['attempts'] + 1;
        $delay = self::RETRY_DELAYS[$attempts - 1] ?? null;
        $failed = $delay === null || $attempts >= self::MAX_ATTEMPTS;
        $error = mb_substr($error, 0, 500);

        $this->db->transaction(function () use ($id, $attempts, $delay, $failed, $error): void {
            $this->db->statement(
                'UPDATE notification_outbox SET status = ?, attempts = ?, last_error = ?, '
                . 'available_at = ?, locked_at = NULL WHERE id = ?',
                [
                    $failed ? 'failed' : 'pending',
                    $attempts,
                    $error,
                    gmdate('Y-m-d H:i:s', time() + ($failed ? 0 : (int) $delay)),
                    $id,
                ],
            );

            $this->db->table('notification_attempts')->insert([
                'outbox_id' => $id,
                'success' => 0,
                'error' => $error,
                'attempted_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    private function recoverStale(): void
    {
        $this->db->statement(
            'UPDATE notification_outbox SET status = ?, locked_at = NULL '
            . 'WHERE status = ? AND locked_at IS NOT NULL AND locked_at < UTC_TIMESTAMP() - INTERVAL '
            . self::STALE_LOCK_SECONDS . ' SECOND',
            ['pending', 'sending'],
        );
    }

    private function cleanupRateLimits(): void
    {
        $this->rateLimits->prune(1);
    }
}
