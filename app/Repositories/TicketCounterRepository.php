<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;
use RuntimeException;

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

    /**
     * Allocate the next ticket number for a tenant. Must run inside the ticket transaction.
     */
    public function allocate(int $tenantId): int
    {
        $this->db->statement(
            'INSERT IGNORE INTO ticket_counters (tenant_id, last_number) VALUES (?, 0)',
            [$tenantId],
        );

        $this->db->statement(
            'INSERT INTO ticket_counters (tenant_id, last_number) VALUES (?, 1) '
            . 'ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$tenantId],
        );

        $row = $this->db->selectOne('SELECT LAST_INSERT_ID() AS allocated');
        $number = (int) ($row['allocated'] ?? 0);

        if ($number < 1) {
            throw new RuntimeException('The ticket counter did not return a number.');
        }

        return $number;
    }
}
