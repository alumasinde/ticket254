<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use App\Database\Paginator;

final class AuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->table('audit_logs')->insert($data);
    }

    /**
     * @param array{action?: ?string, actor_user_id?: ?int, from?: ?string, to?: ?string} $filters
     */
    public function paginate(int $tenantId, array $filters, int $perPage = 30, int $page = 1): Paginator
    {
        [$where, $bindings] = $this->whereSql($tenantId, $filters);

        $count = $this->db->selectOne('SELECT COUNT(*) AS aggregate FROM audit_logs a' . $where, $bindings);
        $total = (int) ($count['aggregate'] ?? 0);

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $rows = $this->db->select(
            'SELECT a.id, a.tenant_id, a.actor_user_id, a.action, a.resource_type, a.resource_id, '
            . 'a.ip_address, a.user_agent, a.metadata, a.created_at, '
            . 'u.first_name AS actor_first_name, u.last_name AS actor_last_name, u.email AS actor_email '
            . 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id'
            . $where
            . ' ORDER BY a.created_at DESC, a.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bindings,
        );

        return new Paginator($rows, $total, $perPage, $page);
    }

    /**
     * @return list<string>
     */
    public function actions(int $tenantId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT action FROM audit_logs WHERE tenant_id = ? ORDER BY action',
            [$tenantId],
        );

        return array_map(static fn (array $row): string => (string) $row['action'], $rows);
    }

    /**
     * @param array{action?: ?string, actor_user_id?: ?int, from?: ?string, to?: ?string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereSql(int $tenantId, array $filters): array
    {
        $conditions = ['a.tenant_id = ?'];
        $bindings = [$tenantId];

        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $conditions[] = 'a.action = ?';
            $bindings[] = (string) $filters['action'];
        }

        if (($filters['actor_user_id'] ?? null) !== null) {
            $conditions[] = 'a.actor_user_id = ?';
            $bindings[] = (int) $filters['actor_user_id'];
        }

        if (($filters['from'] ?? null) !== null) {
            $conditions[] = 'a.created_at >= ?';
            $bindings[] = (string) $filters['from'];
        }

        if (($filters['to'] ?? null) !== null) {
            $conditions[] = 'a.created_at <= ?';
            $bindings[] = (string) $filters['to'];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }
}
