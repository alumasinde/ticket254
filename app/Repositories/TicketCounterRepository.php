<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class TicketCounterRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createForTenant(int $tenantId): void
    {
        $this->db->table('ticket_counters')->insert([
            'tenant_id' => $tenantId,
            'last_number' => 0,
        ]);
    }
}
