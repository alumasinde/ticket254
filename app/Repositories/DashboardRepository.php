<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Services\Tenants\TenantContext;

final class DashboardRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, int>
     */
    public function counts(TenantContext $ctx, string $todayStartUtc, string $todayEndUtc): array
    {
        [$scope, $bindings] = $this->scope($ctx);

        $row = $this->db->selectOne(
            'SELECT '
            . "COALESCE(SUM(st.code = 'open'), 0) AS open_count, "
            . "COALESCE(SUM(st.code = 'assigned'), 0) AS assigned_count, "
            . "COALESCE(SUM(st.code = 'in_progress'), 0) AS in_progress_count, "
            . "COALESCE(SUM(st.code = 'pending'), 0) AS pending_count, "
            . "COALESCE(SUM(st.code = 'resolved'), 0) AS resolved_count, "
            . "COALESCE(SUM(st.code = 'closed'), 0) AS closed_count, "
            . 'COALESCE(SUM(st.is_terminal = 0), 0) AS active_count, '
            . 'COALESCE(SUM(st.is_terminal = 0 AND t.assigned_user_id IS NULL), 0) AS unassigned_count, '
            . 'COALESCE(SUM(st.is_terminal = 0 AND t.due_at IS NOT NULL AND t.due_at < UTC_TIMESTAMP()), 0) AS overdue_count, '
            . 'COALESCE(SUM(t.resolved_at IS NOT NULL AND t.resolved_at >= ? AND t.resolved_at < ?), 0) AS resolved_today_count '
            . 'FROM tickets t INNER JOIN ticket_statuses st ON st.id = t.status_id'
            . $scope,
            array_merge([$todayStartUtc, $todayEndUtc], $bindings),
        );

        return [
            'open' => (int) ($row['open_count'] ?? 0),
            'assigned' => (int) ($row['assigned_count'] ?? 0),
            'in_progress' => (int) ($row['in_progress_count'] ?? 0),
            'pending' => (int) ($row['pending_count'] ?? 0),
            'resolved' => (int) ($row['resolved_count'] ?? 0),
            'closed' => (int) ($row['closed_count'] ?? 0),
            'active' => (int) ($row['active_count'] ?? 0),
            'unassigned' => (int) ($row['unassigned_count'] ?? 0),
            'overdue' => (int) ($row['overdue_count'] ?? 0),
            'resolved_today' => (int) ($row['resolved_today_count'] ?? 0),
        ];
    }

    public function averageResolutionHours(TenantContext $ctx, string $fromUtc, string $toUtc): ?float
    {
        [$scope, $bindings] = $this->scope($ctx);

        $row = $this->db->selectOne(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at)) / 60 AS average_hours '
            . 'FROM tickets t INNER JOIN ticket_statuses st ON st.id = t.status_id'
            . $scope
            . ' AND t.resolved_at IS NOT NULL AND t.resolved_at >= ? AND t.resolved_at <= ?',
            array_merge($bindings, [$fromUtc, $toUtc]),
        );

        $value = $row['average_hours'] ?? null;

        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function needsAttention(TenantContext $ctx, int $limit = 8): array
    {
        [$scope, $bindings] = $this->scope($ctx);
        $limit = max(1, min(20, $limit));

        return $this->db->select(
            $this->selectSql()
            . $scope
            . ' AND st.is_terminal = 0 AND ('
            . 't.due_at < UTC_TIMESTAMP() '
            . 'OR t.assigned_user_id IS NULL '
            . 'OR (t.first_response_at IS NULL AND t.created_at < UTC_TIMESTAMP() - INTERVAL 4 HOUR)'
            . ')'
            . ' ORDER BY (t.due_at IS NOT NULL AND t.due_at < UTC_TIMESTAMP()) DESC, '
            . 'pr.sort_order DESC, t.created_at ASC'
            . ' LIMIT ' . $limit,
            $bindings,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(TenantContext $ctx, int $limit = 6): array
    {
        [$scope, $bindings] = $this->scope($ctx);
        $limit = max(1, min(20, $limit));

        return $this->db->select(
            $this->selectSql()
            . $scope
            . ' ORDER BY t.updated_at DESC, t.id DESC'
            . ' LIMIT ' . $limit,
            $bindings,
        );
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function scope(TenantContext $ctx): array
    {
        $conditions = ['t.tenant_id = ?'];
        $bindings = [$ctx->tenantId];

        if (!$ctx->can('tickets.view_all')) {
            $conditions[] = '(t.assigned_user_id = ? OR t.team_id IN ('
                . 'SELECT vtm.team_id FROM team_members vtm WHERE vtm.tenant_id = ? AND vtm.user_id = ?))';
            array_push($bindings, $ctx->userId, $ctx->tenantId, $ctx->userId);
        }

        return [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    private function selectSql(): string
    {
        return 'SELECT t.id, t.ticket_number, t.subject, t.status_id, t.priority_id, '
            . 't.team_id, t.category_id, t.assigned_user_id, t.due_at, t.first_response_at, '
            . 't.created_at, t.updated_at, '
            . 'st.code AS status_code, st.label AS status_label, st.is_terminal AS status_terminal, '
            . 'pr.code AS priority_code, pr.label AS priority_label, '
            . 't.requester_name, t.requester_email, '
            . 'tm.name AS team_name, cat.name AS category_name, '
            . 'au.first_name AS assignee_first_name, au.last_name AS assignee_last_name '
            . 'FROM tickets t '
            . 'INNER JOIN ticket_statuses st ON st.id = t.status_id '
            . 'INNER JOIN ticket_priorities pr ON pr.id = t.priority_id '
            . 'LEFT JOIN teams tm ON tm.tenant_id = t.tenant_id AND tm.id = t.team_id '
            . 'LEFT JOIN categories cat ON cat.tenant_id = t.tenant_id AND cat.id = t.category_id '
            . 'LEFT JOIN users au ON au.id = t.assigned_user_id';
    }
}
