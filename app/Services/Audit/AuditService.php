<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Repositories\AuditLogRepository;
use App\Services\Tenants\TenantContext;
use App\Support\LocalTime;

final class AuditService
{
    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{rows: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(TenantContext $ctx, array $query, int $perPage = 30): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $filters = [
            'action' => $this->nullable((string) ($query['action'] ?? '')),
            'actor_user_id' => isset($query['actor_user_id']) && $query['actor_user_id'] !== ''
                ? (int) $query['actor_user_id']
                : null,
            'from' => LocalTime::filterBoundary(
                isset($query['from']) ? (string) $query['from'] : null,
                $ctx->timezone,
            ),
            'to' => LocalTime::filterBoundary(
                isset($query['to']) ? (string) $query['to'] : null,
                $ctx->timezone,
                true,
            ),
        ];

        $paginator = $this->logs->paginate($ctx->tenantId, $filters, $perPage, $page);

        return [
            'rows' => array_map($this->present(...), $paginator->items()),
            'meta' => $paginator->meta(),
        ];
    }

    /**
     * @return list<string>
     */
    public function actions(TenantContext $ctx): array
    {
        return $this->logs->actions($ctx->tenantId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $name = trim((string) ($row['actor_first_name'] ?? '') . ' ' . (string) ($row['actor_last_name'] ?? ''));
        $metadata = $row['metadata'] ?? null;

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'resource_type' => (string) $row['resource_type'],
            'resource_id' => $row['resource_id'] === null ? null : (string) $row['resource_id'],
            'actor' => $row['actor_user_id'] === null ? null : [
                'id' => (int) $row['actor_user_id'],
                'name' => $name !== '' ? $name : (string) ($row['actor_email'] ?? ''),
            ],
            'ip_address' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'user_agent' => $row['user_agent'] === null ? null : (string) $row['user_agent'],
            'metadata' => is_array($metadata) ? $metadata : null,
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
