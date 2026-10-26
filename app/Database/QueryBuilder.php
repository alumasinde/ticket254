<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like'];

    private string $columns = '*';

    /** @var list<array{sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(
        private readonly Database $db,
        private readonly string $table,
    ) {
        $this->assertIdentifier($table);
    }

    /**
     * @param string|list<string> $columns
     */
    public function select(string|array $columns = '*'): self
    {
        $list = is_array($columns) ? $columns : array_map('trim', explode(',', $columns));
        $quoted = [];

        foreach ($list as $column) {
            $quoted[] = $this->quoteColumn($column);
        }

        $this->columns = implode(', ', $quoted);

        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = strtolower((string) $operatorOrValue);
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new RuntimeException("Unsupported query operator [$operator].");
        }

        $this->wheres[] = [
            'sql' => $this->quoteColumn($column) . ' ' . strtoupper($operator) . ' ?',
            'bindings' => [$this->normalizeBinding($value)],
        ];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = ['sql' => '0 = 1', 'bindings' => []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $bindings = array_map($this->normalizeBinding(...), array_values($values));

        $this->wheres[] = [
            'sql' => $this->quoteColumn($column) . ' IN (' . $placeholders . ')',
            'bindings' => $bindings,
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'sql' => $this->quoteColumn($column) . ' IS NULL',
            'bindings' => [],
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new RuntimeException("Unsupported sort direction [$direction].");
        }

        $this->orders[] = ['column' => $this->quoteColumn($column), 'direction' => strtoupper($direction)];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $bindings = $this->bindings();
        [$sql, $bindings] = $this->applyOrderingAndWindow($this->selectSql(), $bindings);

        return $this->db->select($sql, $bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;

        $this->limit = 1;
        $this->offset = null;

        try {
            $rows = $this->get();
        } finally {
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;
        }

        return $rows[0] ?? null;
    }

    public function value(string $column): mixed
    {
        $row = $this->select([$column])->first();

        return $row === null ? null : array_values($row)[0];
    }

    public function count(): int
    {
        [$sql, $bindings] = $this->applyOrderingAndWindow(
            'SELECT COUNT(*) AS aggregate FROM ' . $this->quoteColumn($this->table) . $this->whereSql(),
            $this->bindings(),
            false,
        );

        $row = $this->db->selectOne($sql, $bindings);

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        if ($data === []) {
            throw new RuntimeException('Cannot insert an empty row.');
        }

        $columns = array_map($this->quoteColumn(...), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteColumn($this->table),
            implode(', ', $columns),
            $placeholders,
        );

        $this->db->statement($sql, array_map($this->normalizeBinding(...), array_values($data)));

        return (int) $this->db->connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            throw new RuntimeException('Cannot update with an empty row.');
        }

        $this->assertHasWhere('update');

        $assignments = array_map(
            fn (string $column): string => $this->quoteColumn($column) . ' = ?',
            array_keys($data),
        );

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->quoteColumn($this->table),
            implode(', ', $assignments),
            $this->whereSql(),
        );

        $bindings = array_merge(array_map($this->normalizeBinding(...), array_values($data)), $this->bindings());

        return $this->db->statement($sql, $bindings)->rowCount();
    }

    public function delete(): int
    {
        $this->assertHasWhere('delete');

        $sql = 'DELETE FROM ' . $this->quoteColumn($this->table) . $this->whereSql();

        return $this->db->statement($sql, $this->bindings())->rowCount();
    }

    public function paginate(int $perPage = 25, int $page = 1): Paginator
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $total = $this->count();

        $this->limit($perPage)->offset(($page - 1) * $perPage);

        return new Paginator($this->get(), $total, $perPage, $page);
    }

    private function selectSql(): string
    {
        return 'SELECT ' . $this->columns . ' FROM ' . $this->quoteColumn($this->table) . $this->whereSql();
    }

    /**
     * @param list<mixed> $bindings
     * @return array{0: string, 1: list<mixed>}
     */
    private function applyOrderingAndWindow(string $sql, array $bindings, bool $withWindow = true): array
    {
        if ($this->orders !== []) {
            $parts = array_map(
                static fn (array $order): string => $order['column'] . ' ' . $order['direction'],
                $this->orders,
            );

            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if (!$withWindow) {
            return [$sql, $bindings];
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset !== null) {
            $sql .= ' LIMIT 18446744073709551615';
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return [$sql, $bindings];
    }

    private function whereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $parts = array_map(static fn (array $where): string => $where['sql'], $this->wheres);

        return ' WHERE ' . implode(' AND ', $parts);
    }

    /**
     * @return list<mixed>
     */
    private function bindings(): array
    {
        $bindings = [];

        foreach ($this->wheres as $where) {
            foreach ($where['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    private function assertHasWhere(string $operation): void
    {
        if ($this->wheres === []) {
            throw new RuntimeException("Refusing to {$operation} every row in [{$this->table}]; add a where clause.");
        }
    }

    private function quoteColumn(string $column): string
    {
        $column = trim($column);

        if ($column === '*') {
            return '*';
        }

        $parts = explode('.', $column);

        if (count($parts) > 2) {
            throw new RuntimeException("Invalid column [$column].");
        }

        foreach ($parts as $part) {
            $this->assertIdentifier($part);
        }

        return implode('.', array_map(static fn (string $part): string => '`' . $part . '`', $parts));
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException("Invalid SQL identifier [$identifier].");
        }
    }

    private function normalizeBinding(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
