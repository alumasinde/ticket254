<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use RuntimeException;

final class RoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function idByCode(string $code): int
    {
        $row = $this->db->table('roles')->where('code', $code)->first();

        if ($row === null) {
            throw new RuntimeException("Role [$code] is missing from the reference data.");
        }

        return (int) $row['id'];
    }

    /**
     * @return list<string>
     */
    public function permissionsForRole(int $roleId): array
    {
        $rows = $this->db->select(
            'SELECT p.code FROM role_permissions rp '
            . 'INNER JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE rp.role_id = ? ORDER BY p.code',
            [$roleId],
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }
}
