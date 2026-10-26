<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use RuntimeException;

final class TenantRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    private const UPDATABLE = [
        'name', 'slug', 'public_key', 'public_form_mode', 'public_form_enabled',
        'status', 'timezone', 'contact_email', 'contact_phone', 'settings_json',
        'suspended_at', 'suspended_reason',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->table('tenants')->insert($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('tenants')->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->table('tenants')->where('slug', $slug)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicKey(string $publicKey): ?array
    {
        return $this->db->table('tenants')->where('public_key', $publicKey)->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        foreach (array_keys($data) as $column) {
            if (!in_array($column, self::UPDATABLE, true)) {
                throw new RuntimeException("Tenant column [$column] cannot be updated.");
            }
        }

        $this->db->table('tenants')->where('id', $id)->update($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tenantForActiveDomain(string $domain): ?array
    {
        return $this->db->selectOne(
            'SELECT t.* FROM tenant_domains d '
            . 'INNER JOIN tenants t ON t.id = d.tenant_id '
            . 'WHERE d.domain = ? AND d.status = ? LIMIT 1',
            [strtolower($domain), 'active'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeDomainForTenant(int $tenantId): ?array
    {
        return $this->db->selectOne(
            'SELECT domain FROM tenant_domains WHERE tenant_id = ? AND status = ? ORDER BY id ASC LIMIT 1',
            [$tenantId, 'active'],
        );
    }
}
