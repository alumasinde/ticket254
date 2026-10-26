<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class PlatformRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, int>
     */
    public function overview(): array
    {
        $tenants = $this->db->selectOne(
            "SELECT COUNT(*) AS total, "
            . "COALESCE(SUM(status = 'active'), 0) AS active_count, "
            . "COALESCE(SUM(status = 'suspended'), 0) AS suspended_count, "
            . "COALESCE(SUM(status = 'closed'), 0) AS closed_count "
            . 'FROM tenants',
        );
        $users = $this->db->selectOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active_count FROM users",
        );
        $tickets = $this->db->selectOne(
            'SELECT COUNT(*) AS total, '
            . "COALESCE(SUM(created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')), 0) AS this_month "
            . 'FROM tickets',
        );
        $outbox = $this->db->selectOne(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending, "
            . "COALESCE(SUM(status = 'failed'), 0) AS failed "
            . 'FROM notification_outbox',
        );

        return [
            'tenants_total' => (int) ($tenants['total'] ?? 0),
            'tenants_active' => (int) ($tenants['active_count'] ?? 0),
            'tenants_suspended' => (int) ($tenants['suspended_count'] ?? 0),
            'tenants_closed' => (int) ($tenants['closed_count'] ?? 0),
            'users_total' => (int) ($users['total'] ?? 0),
            'users_active' => (int) ($users['active_count'] ?? 0),
            'tickets_total' => (int) ($tickets['total'] ?? 0),
            'tickets_this_month' => (int) ($tickets['this_month'] ?? 0),
            'outbox_pending' => (int) ($outbox['pending'] ?? 0),
            'outbox_failed' => (int) ($outbox['failed'] ?? 0),
        ];
    }

    /**
     * @param array{status?: ?string, q?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function tenants(array $filters = []): array
    {
        $conditions = ['1 = 1'];
        $bindings = [];

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $conditions[] = 't.status = ?';
            $bindings[] = (string) $filters['status'];
        }

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $conditions[] = '(t.name LIKE ? OR t.slug LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($bindings, $like, $like);
        }

        return $this->db->select(
            $this->selectSql()
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY t.created_at DESC, t.id DESC',
            $bindings,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tenant(int $id): ?array
    {
        return $this->db->selectOne($this->selectSql() . ' WHERE t.id = ? LIMIT 1', [$id]);
    }

    /**
     * @return array<string, int>
     */
    public function usage(int $tenantId): array
    {
        $members = $this->db->selectOne(
            "SELECT COUNT(*) AS agents FROM tenant_memberships WHERE tenant_id = ? AND status = 'active'",
            [$tenantId],
        );
        $tickets = $this->db->selectOne(
            'SELECT COUNT(*) AS total, '
            . "COALESCE(SUM(created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')), 0) AS this_month "
            . 'FROM tickets WHERE tenant_id = ?',
            [$tenantId],
        );
        $outbox = $this->db->selectOne(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending FROM notification_outbox WHERE tenant_id = ?",
            [$tenantId],
        );

        return [
            'agents' => (int) ($members['agents'] ?? 0),
            'tickets_total' => (int) ($tickets['total'] ?? 0),
            'tickets_this_month' => (int) ($tickets['this_month'] ?? 0),
            'outbox_pending' => (int) ($outbox['pending'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        return $this->db->select(
            'SELECT id, code, name, monthly_price_minor, currency, max_agents, max_tickets_per_month '
            . 'FROM plans WHERE is_active = 1 ORDER BY monthly_price_minor ASC, id ASC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planByCode(string $code): ?array
    {
        return $this->db->table('plans')->where('code', $code)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function subscription(int $tenantId): ?array
    {
        return $this->db->selectOne(
            'SELECT s.id, s.status, s.current_period_start, s.current_period_end, s.cancelled_at, '
            . 's.notes, p.code AS plan_code, p.name AS plan_name, p.monthly_price_minor, p.currency '
            . 'FROM subscriptions s INNER JOIN plans p ON p.id = s.plan_id '
            . 'WHERE s.tenant_id = ? ORDER BY s.id DESC LIMIT 1',
            [$tenantId],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveSubscription(int $tenantId, array $data): int
    {
        $current = $this->subscription($tenantId);
        $row = [
            'plan_id' => (int) $data['plan_id'],
            'status' => (string) $data['status'],
            'current_period_start' => $data['current_period_start'] ?? null,
            'current_period_end' => $data['current_period_end'] ?? null,
            'cancelled_at' => $data['cancelled_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($current === null) {
            return $this->db->table('subscriptions')->insert(['tenant_id' => $tenantId] + $row);
        }

        $this->db->table('subscriptions')->where('id', (int) $current['id'])->update($row);

        return (int) $current['id'];
    }

    /**
     * @return array<string, int>
     */
    public function outbox(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total, "
            . "COALESCE(SUM(status = 'pending'), 0) AS pending, "
            . "COALESCE(SUM(status = 'processing'), 0) AS processing, "
            . "COALESCE(SUM(status = 'failed'), 0) AS failed, "
            . "COALESCE(SUM(status = 'sent'), 0) AS sent "
            . 'FROM notification_outbox',
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
        ];
    }

    public function databaseReachable(): bool
    {
        try {
            $row = $this->db->selectOne('SELECT 1 AS ok');

            return (int) ($row['ok'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function selectSql(): string
    {
        return 'SELECT t.id, t.name, t.slug, t.status, t.timezone, t.contact_email, t.contact_phone, '
            . 't.suspended_at, t.suspended_reason, t.created_at, '
            . 's.status AS subscription_status, s.current_period_end, '
            . 'p.code AS plan_code, p.name AS plan_name, '
            . '(SELECT COUNT(*) FROM tenant_memberships tm WHERE tm.tenant_id = t.id AND tm.status = \'active\') AS agent_count '
            . 'FROM tenants t '
            . 'LEFT JOIN subscriptions s ON s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.tenant_id = t.id) '
            . 'LEFT JOIN plans p ON p.id = s.plan_id';
    }
}
