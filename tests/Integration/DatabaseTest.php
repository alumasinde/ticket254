<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Database;
use RuntimeException;
use Tests\TestCase;

final class DatabaseTest extends TestCase
{
    public function test_session_timezone_is_utc(): void
    {
        $row = $this->db()->selectOne('SELECT @@session.time_zone AS timezone');

        $this->assertSame('+00:00', $row['timezone']);
    }

    public function test_transactions_roll_back_on_exception(): void
    {
        $db = $this->db();
        $db->statement('CREATE TEMPORARY TABLE tx_probe (id INT PRIMARY KEY, name VARCHAR(20) NOT NULL)');
        $thrown = false;

        try {
            $db->transaction(function (Database $db): void {
                $db->statement('INSERT INTO tx_probe (id, name) VALUES (?, ?)', [1, 'rolled back']);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception->getMessage() === 'boom';
        }

        $this->assertTrue($thrown);
        $row = $db->selectOne('SELECT COUNT(*) AS aggregate FROM tx_probe');
        $this->assertSame(0, (int) $row['aggregate']);
    }

    public function test_transactions_commit_on_success(): void
    {
        $db = $this->db();
        $db->statement('CREATE TEMPORARY TABLE tx_commit_probe (id INT PRIMARY KEY)');

        $db->transaction(static function (Database $db): void {
            $db->statement('INSERT INTO tx_commit_probe (id) VALUES (?)', [7]);
        });

        $row = $db->selectOne('SELECT COUNT(*) AS aggregate FROM tx_commit_probe');
        $this->assertSame(1, (int) $row['aggregate']);
    }
}
