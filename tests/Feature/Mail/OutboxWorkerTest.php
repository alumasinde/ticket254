<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\ArrayMailer;
use App\Mail\EmailMessage;
use App\Mail\Mailer;
use App\Services\Notifications\OutboxWorker;
use RuntimeException;
use Tests\Support\BuildsPublicAccess;
use Tests\Support\BuildsTickets;
use Tests\Support\BuildsWorkspaces;
use Tests\TestCase;

final class OutboxWorkerTest extends TestCase
{
    use BuildsPublicAccess;
    use BuildsTickets;
    use BuildsWorkspaces;

    public function test_a_queued_email_is_sent_and_recorded(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->markExistingOutboxSent();
        $created = $this->createPublicRequest($fixture['slug'], ['subject' => 'Cold water']);
        $outbox = $this->outboxFor($fixture['tenant_id'], 1, 'ticket_created');

        $mailer = new ArrayMailer();
        $this->app->container()->instance(Mailer::class, $mailer);

        $report = $this->app->container()->get(OutboxWorker::class)->work();

        $this->assertSame(['claimed' => 1, 'sent' => 1, 'failed' => 0], $report);
        $this->assertCount(1, $mailer->messages);

        $message = $mailer->messages[0];
        $this->assertSame('njeri.wambui@example.co.ke', $message->to);
        $this->assertSame('Njeri Wambui', $message->name);
        $this->assertSame('Glee Hotel: we received your request (HD-000001)', $message->subject);
        $this->assertStringContainsString((string) $created['tracking_url'], $message->html);
        $this->assertStringContainsString((string) $created['tracking_url'], $message->text);

        $row = $this->outboxRow((int) $outbox['id']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['locked_at']);

        $attempts = $this->attemptsFor((int) $outbox['id']);
        $this->assertCount(1, $attempts);
        $this->assertSame(1, (int) $attempts[0]['success']);
    }

    public function test_failures_back_off_and_are_marked_failed_after_six_attempts(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->markExistingOutboxSent();
        $this->createPublicRequest($fixture['slug']);
        $outbox = $this->outboxFor($fixture['tenant_id'], 1, 'ticket_created');

        $failing = new class implements Mailer {
            public int $calls = 0;

            public function send(EmailMessage $message): void
            {
                $this->calls++;
                throw new RuntimeException('SMTP is unreachable.');
            }
        };

        $this->app->container()->instance(Mailer::class, $failing);
        $worker = $this->app->container()->get(OutboxWorker::class);

        $first = $worker->work();
        $this->assertSame(['claimed' => 1, 'sent' => 0, 'failed' => 1], $first);

        $row = $this->outboxRow((int) $outbox['id']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('SMTP is unreachable.', $row['last_error']);
        $delay = strtotime((string) $row['available_at'] . ' UTC') - time();
        $this->assertGreaterThanOrEqual(55, $delay);
        $this->assertLessThanOrEqual(65, $delay);

        for ($attempt = 2; $attempt <= 6; $attempt++) {
            $this->db()->statement(
                'UPDATE notification_outbox SET available_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?',
                [(int) $outbox['id']],
            );

            $report = $worker->work();
            $this->assertSame(1, $report['claimed'], 'Attempt ' . $attempt);
            $this->assertSame(1, $report['failed'], 'Attempt ' . $attempt);

            $row = $this->outboxRow((int) $outbox['id']);
            $this->assertSame($attempt, (int) $row['attempts']);

            if ($attempt === 2) {
                $backoff = strtotime((string) $row['available_at'] . ' UTC') - time();
                $this->assertGreaterThanOrEqual(290, $backoff);
                $this->assertLessThanOrEqual(305, $backoff);
            }
        }

        $this->assertSame('failed', $row['status']);
        $this->assertSame(6, (int) $row['attempts']);
        $this->assertSame(6, $failing->calls);
        $this->assertCount(6, $this->attemptsFor((int) $outbox['id']));
        $this->assertSame(
            0,
            (int) $this->db()->selectOne(
                'SELECT COUNT(*) AS aggregate FROM notification_attempts WHERE outbox_id = ? AND success = 1',
                [(int) $outbox['id']],
            )['aggregate'],
        );
    }

    public function test_a_stale_sending_lock_is_recovered(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->markExistingOutboxSent();
        $this->createPublicRequest($fixture['slug']);
        $outbox = $this->outboxFor($fixture['tenant_id'], 1, 'ticket_created');

        $this->db()->statement(
            'UPDATE notification_outbox SET status = ?, locked_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id = ?',
            ['sending', (int) $outbox['id']],
        );

        $mailer = new ArrayMailer();
        $this->app->container()->instance(Mailer::class, $mailer);

        $report = $this->app->container()->get(OutboxWorker::class)->work();

        $this->assertSame(['claimed' => 1, 'sent' => 1, 'failed' => 0], $report);
        $this->assertSame('sent', $this->outboxRow((int) $outbox['id'])['status']);
        $this->assertCount(1, $mailer->messages);
    }

    public function test_the_worker_honours_the_limit_and_cleans_expired_rate_limits(): void
    {
        $fixture = $this->registerWorkspaceFixture('Glee Hotel');
        $this->markExistingOutboxSent();
        $this->createPublicRequest($fixture['slug'], ['subject' => 'One']);
        $this->createPublicRequest($fixture['slug'], ['subject' => 'Two']);

        $this->db()->table('rate_limits')->insert([
            'limiter_key' => 'expired:test',
            'hits' => 2,
            'window_started_at' => gmdate('Y-m-d H:i:s', time() - 172800),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 172800),
        ]);

        $mailer = new ArrayMailer();
        $this->app->container()->instance(Mailer::class, $mailer);
        $worker = $this->app->container()->get(OutboxWorker::class);

        $first = $worker->work(1);
        $this->assertSame(['claimed' => 1, 'sent' => 1, 'failed' => 0], $first);
        $this->assertCount(1, $mailer->messages);
        $this->assertNull(
            $this->db()->table('rate_limits')->where('limiter_key', 'expired:test')->first(),
        );

        $second = $worker->work(1);
        $this->assertSame(['claimed' => 1, 'sent' => 1, 'failed' => 0], $second);
        $this->assertCount(2, $mailer->messages);

        $third = $worker->work(1);
        $this->assertSame(['claimed' => 0, 'sent' => 0, 'failed' => 0], $third);
    }

    private function markExistingOutboxSent(): void
    {
        $this->db()->statement(
            "UPDATE notification_outbox SET status = 'sent', sent_at = UTC_TIMESTAMP() "
            . "WHERE status IN ('pending', 'sending')",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxFor(int $tenantId, int $ticketNumber, string $eventType): array
    {
        $ticket = $this->ticketRow($tenantId, $ticketNumber);
        $row = $this->db()->table('notification_outbox')
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', (int) $ticket['id'])
            ->where('event_type', $eventType)
            ->first();

        $this->assertNotNull($row, "No [$eventType] outbox row was queued.");

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxRow(int $id): array
    {
        $row = $this->db()->table('notification_outbox')->where('id', $id)->first();

        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attemptsFor(int $outboxId): array
    {
        return $this->db()->select(
            'SELECT * FROM notification_attempts WHERE outbox_id = ? ORDER BY id ASC',
            [$outboxId],
        );
    }
}
