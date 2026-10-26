<?php

declare(strict_types=1);

namespace App\Services\Tickets;

final class TicketFilters
{
    public const SORTS = [
        '-created_at',
        'created_at',
        '-updated_at',
        'updated_at',
        '-due_at',
        'due_at',
        '-priority',
        'priority',
    ];

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?string $status = null,
        public readonly ?string $priority = null,
        public readonly ?int $teamId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $siteId = null,
        public readonly ?int $assignedUserId = null,
        public readonly ?string $createdFrom = null,
        public readonly ?string $createdTo = null,
        public readonly bool $overdue = false,
        public readonly bool $unassigned = false,
        public readonly bool $mine = false,
        public readonly string $sort = '-created_at',
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $sort = self::text($query, 'sort', 30);

        return new self(
            q: self::text($query, 'q', 120),
            status: self::text($query, 'status', 30),
            priority: self::text($query, 'priority', 30),
            teamId: self::id($query, 'team_id'),
            categoryId: self::id($query, 'category_id'),
            siteId: self::id($query, 'site_id'),
            assignedUserId: self::id($query, 'assigned_user_id'),
            createdFrom: self::text($query, 'created_from', 40),
            createdTo: self::text($query, 'created_to', 40),
            overdue: self::flag($query, 'overdue'),
            unassigned: self::flag($query, 'unassigned'),
            mine: self::flag($query, 'mine'),
            sort: in_array($sort, self::SORTS, true) ? $sort : '-created_at',
            page: max(1, self::int($query, 'page', 1)),
            perPage: max(1, min(100, self::int($query, 'per_page', 25))),
        );
    }

    /**
     * Values for the filter form.
     *
     * @return array<string, mixed>
     */
    public function toView(): array
    {
        return [
            'q' => $this->q,
            'status' => $this->status,
            'priority' => $this->priority,
            'team_id' => $this->teamId,
            'category_id' => $this->categoryId,
            'site_id' => $this->siteId,
            'assigned_user_id' => $this->assignedUserId,
            'created_from' => $this->createdFrom,
            'created_to' => $this->createdTo,
            'overdue' => $this->overdue,
            'unassigned' => $this->unassigned,
            'mine' => $this->mine,
            'sort' => $this->sort,
        ];
    }

    /**
     * Non-empty filters for pagination links.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        $query = array_filter($this->toView(), static fn (mixed $value): bool => $value !== null && $value !== false && $value !== '');

        if ($this->sort !== '-created_at') {
            $query['sort'] = $this->sort;
        } else {
            unset($query['sort']);
        }

        if ($this->perPage !== 25) {
            $query['per_page'] = $this->perPage;
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function text(array $query, string $key, int $maxLength): ?string
    {
        $value = $query[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function id(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;

        if (!is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function int(array $query, string $key, int $default): int
    {
        $value = $query[$key] ?? null;

        return is_scalar($value) && preg_match('/^\d+$/', (string) $value) === 1
            ? (int) $value
            : $default;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function flag(array $query, string $key): bool
    {
        $value = $query[$key] ?? null;

        return is_scalar($value)
            && in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
