<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Database\Paginator;
use App\Services\Tenants\TenantContext;
use App\Services\Tickets\TicketFilters;
use App\Support\LocalTime;
use App\Support\TicketReference;
use RuntimeException;

final class TicketRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createForTenant(int $tenantId, array $data): int
    {
        return $this->db->table('tickets')->insert(['tenant_id' => $tenantId] + $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNumber(TenantContext $ctx, int $number): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE t.tenant_id = ? AND t.ticket_number = ? LIMIT 1',
            [$ctx->tenantId, $number],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNumberForTenant(int $tenantId, int $number): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE t.tenant_id = ? AND t.ticket_number = ? LIMIT 1',
            [$tenantId, $number],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForTenant(int $tenantId, int $id): ?array
    {
        return $this->db->selectOne(
            $this->selectSql() . ' WHERE t.tenant_id = ? AND t.id = ? LIMIT 1',
            [$tenantId, $id],
        );
    }

    /**
     * Updates a ticket outside optimistic locking, for requester actions on a tracking token.
     *
     * @param array<string, mixed> $data
     */
    public function updateForTenant(int $tenantId, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $allowed = ['status_id', 'resolved_at', 'closed_at'];
        $assignments = [];

        foreach ($data as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new RuntimeException("Ticket column [$column] cannot be updated without a version.");
            }

            $assignments[] = '`' . $column . '` = ?';
        }

        return $this->db->statement(
            'UPDATE tickets SET ' . implode(', ', $assignments) . ', version = version + 1 '
            . 'WHERE tenant_id = ? AND id = ?',
            array_merge(array_values($data), [$tenantId, $id]),
        )->rowCount();
    }

    public function paginate(TenantContext $ctx, TicketFilters $filters): Paginator
    {
        [$where, $bindings] = $this->whereSql($ctx, $filters);

        $count = $this->db->selectOne(
            'SELECT COUNT(*) AS aggregate ' . $this->fromSql() . $where,
            $bindings,
        );
        $total = (int) ($count['aggregate'] ?? 0);

        $rows = $this->db->select(
            $this->selectSql() . $where
            . ' ORDER BY ' . $this->orderSql($filters->sort)
            . ' LIMIT ' . $filters->perPage
            . ' OFFSET ' . (($filters->page - 1) * $filters->perPage),
            $bindings,
        );

        return new Paginator($rows, $total, $filters->perPage, $filters->page);
    }

    public function currentVersion(TenantContext $ctx, int $id): ?int
    {
        $value = $this->db->table('tickets')
            ->where('tenant_id', $ctx->tenantId)
            ->where('id', $id)
            ->value('version');

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateVersioned(TenantContext $ctx, int $id, int $version, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $allowed = [
            'subject', 'description', 'category_id', 'site_id',
            'team_id', 'assigned_user_id', 'status_id', 'priority_id',
            'due_at', 'resolved_at', 'closed_at',
        ];

        foreach (array_keys($data) as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new RuntimeException("Ticket column [$column] cannot be updated.");
            }
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => '`' . $column . '` = ?',
            array_keys($data),
        ));

        return $this->db->statement(
            'UPDATE tickets SET ' . $assignments . ', version = version + 1 '
            . 'WHERE tenant_id = ? AND id = ? AND version = ?',
            array_merge(array_values($data), [$ctx->tenantId, $id, $version]),
        )->rowCount();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereSql(TenantContext $ctx, TicketFilters $filters): array
    {
        $conditions = ['t.tenant_id = ?'];
        $bindings = [$ctx->tenantId];

        if (!$ctx->can('tickets.view_all')) {
            $conditions[] = '(t.assigned_user_id = ? OR t.team_id IN ('
                . 'SELECT vtm.team_id FROM team_members vtm WHERE vtm.tenant_id = ? AND vtm.user_id = ?))';
            array_push($bindings, $ctx->userId, $ctx->tenantId, $ctx->userId);
        }

        if ($filters->status !== null) {
            $conditions[] = 'st.code = ?';
            $bindings[] = $filters->status;
        }

        if ($filters->priority !== null) {
            $conditions[] = 'pr.code = ?';
            $bindings[] = $filters->priority;
        }

        if ($filters->teamId !== null) {
            $conditions[] = 't.team_id = ?';
            $bindings[] = $filters->teamId;
        }

        if ($filters->categoryId !== null) {
            $conditions[] = 't.category_id = ?';
            $bindings[] = $filters->categoryId;
        }

        if ($filters->siteId !== null) {
            $conditions[] = 't.site_id = ?';
            $bindings[] = $filters->siteId;
        }

        if ($filters->assignedUserId !== null) {
            $conditions[] = 't.assigned_user_id = ?';
            $bindings[] = $filters->assignedUserId;
        }

        if ($filters->unassigned) {
            $conditions[] = 't.assigned_user_id IS NULL';
        }

        if ($filters->mine) {
            $conditions[] = 't.assigned_user_id = ?';
            $bindings[] = $ctx->userId;
        }

        if ($filters->overdue) {
            $conditions[] = 't.due_at IS NOT NULL AND t.due_at < UTC_TIMESTAMP() AND st.is_terminal = 0';
        }

        if ($filters->q !== null) {
            $like = '%' . $filters->q . '%';
            $number = TicketReference::parse($filters->q);

            if ($number === null && preg_match('/^\d+$/', $filters->q) === 1 && (int) $filters->q > 0) {
                $number = (int) $filters->q;
            }

            $condition = '(t.subject LIKE ? OR t.requester_name LIKE ? OR t.requester_email LIKE ?';
            array_push($bindings, $like, $like, $like);

            if ($number !== null) {
                $condition .= ' OR t.ticket_number = ?';
                $bindings[] = $number;
            }

            $conditions[] = $condition . ')';
        }

        $from = LocalTime::filterBoundary($filters->createdFrom, $ctx->timezone);

        if ($from !== null) {
            $conditions[] = 't.created_at >= ?';
            $bindings[] = $from;
        }

        $to = LocalTime::filterBoundary($filters->createdTo, $ctx->timezone, true);

        if ($to !== null) {
            $conditions[] = 't.created_at <= ?';
            $bindings[] = $to;
        }

        return [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    private function orderSql(string $sort): string
    {
        return match ($sort) {
            'created_at' => 't.created_at ASC, t.id ASC',
            'updated_at' => 't.updated_at ASC, t.id ASC',
            '-updated_at' => 't.updated_at DESC, t.id DESC',
            'due_at' => 't.due_at IS NULL ASC, t.due_at ASC, t.id ASC',
            '-due_at' => 't.due_at IS NULL ASC, t.due_at DESC, t.id DESC',
            'priority' => 'pr.sort_order ASC, t.id ASC',
            '-priority' => 'pr.sort_order DESC, t.id DESC',
            default => 't.created_at DESC, t.id DESC',
        };
    }

    private function selectSql(): string
    {
        return 'SELECT ' . $this->columns() . ' ' . $this->fromSql();
    }

    private function fromSql(): string
    {
        return 'FROM tickets t '
            . 'INNER JOIN ticket_statuses st ON st.id = t.status_id '
            . 'INNER JOIN ticket_priorities pr ON pr.id = t.priority_id '
            . 'LEFT JOIN teams tm ON tm.tenant_id = t.tenant_id AND tm.id = t.team_id '
            . 'LEFT JOIN categories cat ON cat.tenant_id = t.tenant_id AND cat.id = t.category_id '
            . 'LEFT JOIN sites si ON si.tenant_id = t.tenant_id AND si.id = t.site_id '
            . 'LEFT JOIN users au ON au.id = t.assigned_user_id';
    }

    private function columns(): string
    {
        return 't.id, t.tenant_id, t.ticket_number, t.site_id, t.category_id, t.requester_user_id, '
            . 't.requester_name, t.requester_email, t.requester_phone, t.team_id, t.assigned_user_id, '
            . 't.subject, t.description, t.status_id, t.priority_id, t.source, t.version, '
            . 't.due_at, t.first_response_at, t.resolved_at, t.closed_at, t.created_at, t.updated_at, '
            . 'st.code AS status_code, st.label AS status_label, st.is_terminal AS status_terminal, '
            . 'pr.code AS priority_code, pr.label AS priority_label, pr.sort_order AS priority_sort, '
            . 'tm.name AS team_name, cat.name AS category_name, si.name AS site_name, '
            . 'au.first_name AS assignee_first_name, au.last_name AS assignee_last_name, '
            . 'au.email AS assignee_email';
    }
}
