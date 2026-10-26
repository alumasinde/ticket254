<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class SlugHistoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(int $tenantId, string $oldSlug): void
    {
        $this->db->statement(
            'INSERT INTO slug_history (tenant_id, old_slug) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)',
            [$tenantId, $oldSlug],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tenantForOldSlug(string $oldSlug): ?array
    {
        return $this->db->selectOne(
            'SELECT t.* FROM slug_history h '
            . 'INNER JOIN tenants t ON t.id = h.tenant_id '
            . 'WHERE h.old_slug = ? LIMIT 1',
            [$oldSlug],
        );
    }
}
