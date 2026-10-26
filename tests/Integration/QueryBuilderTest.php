<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Database;
use RuntimeException;
use Tests\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->db();
        $this->database->statement(
            'CREATE TEMPORARY TABLE qb_probe ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'name VARCHAR(50) NOT NULL, '
            . 'qty INT NOT NULL, '
            . 'note VARCHAR(50) NULL, '
            . 'created_at DATETIME NOT NULL'
            . ')',
        );

        foreach ([
            ['alpha', 1, 'first', '2026-10-01 08:00:00'],
            ['beta', 2, null, '2026-10-02 08:00:00'],
            ['gamma', 3, null, '2026-10-03 08:00:00'],
            ['delta', 4, 'last', '2026-10-04 08:00:00'],
        ] as [$name, $qty, $note, $createdAt]) {
            $this->database->table('qb_probe')->insert([
                'name' => $name,
                'qty' => $qty,
                'note' => $note,
                'created_at' => $createdAt,
            ]);
        }
    }

    public function test_insert_returns_the_new_id_and_rows_are_readable(): void
    {
        $id = $this->database->table('qb_probe')->insert([
            'name' => 'epsilon',
            'qty' => 5,
            'created_at' => '2026-10-05 08:00:00',
        ]);

        $this->assertSame(5, $id);

        $row = $this->database->table('qb_probe')->where('name', 'epsilon')->first();
        $this->assertSame('epsilon', $row['name']);
        $this->assertSame(5, (int) $row['qty']);
    }

    public function test_where_ordering_and_count(): void
    {
        $rows = $this->database->table('qb_probe')
            ->where('qty', '>=', 2)
            ->orderBy('qty', 'desc')
            ->get();

        $this->assertSame(['delta', 'gamma', 'beta'], array_column($rows, 'name'));
        $this->assertSame(3, $this->database->table('qb_probe')->where('qty', '>', 1)->count());
    }

    public function test_where_in_and_empty_where_in(): void
    {
        $this->assertSame(2, $this->database->table('qb_probe')->whereIn('name', ['alpha', 'delta'])->count());
        $this->assertSame(0, $this->database->table('qb_probe')->whereIn('name', [])->count());
    }

    public function test_where_null(): void
    {
        $this->assertSame(2, $this->database->table('qb_probe')->whereNull('note')->count());
    }

    public function test_value_and_first_return_null_when_empty(): void
    {
        $this->assertSame('gamma', $this->database->table('qb_probe')->where('qty', 3)->value('name'));
        $this->assertNull($this->database->table('qb_probe')->where('qty', 99)->first());
    }

    public function test_pagination_metadata(): void
    {
        $page = $this->database->table('qb_probe')->orderBy('id')->paginate(2, 2);

        $this->assertSame(['gamma', 'delta'], array_column($page->items(), 'name'));
        $this->assertSame(4, $page->total());
        $this->assertSame(2, $page->currentPage());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame([
            'page' => 2,
            'per_page' => 2,
            'total' => 4,
            'total_pages' => 2,
        ], $page->meta());
    }

    public function test_update_and_delete(): void
    {
        $updated = $this->database->table('qb_probe')->where('name', 'beta')->update(['qty' => 20]);
        $this->assertSame(1, $updated);
        $this->assertSame(20, (int) $this->database->table('qb_probe')->where('name', 'beta')->value('qty'));

        $deleted = $this->database->table('qb_probe')->where('name', 'alpha')->delete();
        $this->assertSame(1, $deleted);
        $this->assertSame(3, $this->database->table('qb_probe')->count());
    }

    public function test_update_without_where_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->database->table('qb_probe')->update(['qty' => 0]);
    }

    public function test_delete_without_where_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->database->table('qb_probe')->delete();
    }

    public function test_invalid_identifiers_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->database->table('qb_probe; DROP TABLE users');
    }

    public function test_invalid_operator_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->database->table('qb_probe')->where('qty', 'OR 1=1', 1);
    }
}
