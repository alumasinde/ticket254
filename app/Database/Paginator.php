<?php

declare(strict_types=1);

namespace App\Database;

final class Paginator
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public function meta(): array
    {
        return [
            'page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->lastPage(),
        ];
    }
}
