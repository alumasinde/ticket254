<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->table('users')->insert($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->table('users')->where('email', strtolower(trim($email)))->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('users')->where('id', $id)->first();
    }

    public function recordLogin(int $id): void
    {
        $this->db->table('users')->where('id', $id)->update([
            'last_login_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function incrementAuthVersion(int $id): void
    {
        $this->db->statement(
            'UPDATE users SET auth_version = auth_version + 1 WHERE id = ?',
            [$id],
        );
    }
}
