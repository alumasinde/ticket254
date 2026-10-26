<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class WorkspaceTemplate
{
    public function __construct(private readonly Database $db)
    {
    }

    public function apply(int $tenantId): void
    {
        $teamIds = [];

        foreach (['IT', 'Engineering', 'Housekeeping', 'Front Office'] as $name) {
            $teamIds[$name] = $this->db->table('teams')->insert([
                'tenant_id' => $tenantId,
                'name' => $name,
            ]);
        }

        $categories = [
            ['Wi-Fi/Network', 'IT'],
            ['Computer/Printer', 'IT'],
            ['Room maintenance', 'Engineering'],
            ['Housekeeping', 'Housekeeping'],
            ['POS/Systems', 'IT'],
            ['Other', null],
        ];

        foreach ($categories as [$name, $team]) {
            $this->db->table('categories')->insert([
                'tenant_id' => $tenantId,
                'name' => $name,
                'team_id' => $team === null ? null : $teamIds[$team],
            ]);
        }
    }
}
