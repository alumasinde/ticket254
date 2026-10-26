<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class TicketReferenceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statuses(): array
    {
        return $this->db->table('ticket_statuses')->orderBy('sort_order')->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function priorities(): array
    {
        return $this->db->table('ticket_priorities')->orderBy('sort_order')->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function statusByCode(string $code): ?array
    {
        return $this->db->table('ticket_statuses')->where('code', $code)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function priorityByCode(string $code): ?array
    {
        return $this->db->table('ticket_priorities')->where('code', $code)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function priorityById(int $id): ?array
    {
        return $this->db->table('ticket_priorities')->where('id', $id)->first();
    }
}
