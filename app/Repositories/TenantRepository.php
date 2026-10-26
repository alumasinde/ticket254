<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class TenantRepository
{
    public function __construct(private readonly Database $db)
    {
    }

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
}
