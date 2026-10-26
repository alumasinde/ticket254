<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Database\Database;
use App\Database\MigrationException;
use App\Database\Migrator;
use PDO;
use Tests\TestCase;

final class MigratorTest extends TestCase
{
    public function test_a_fresh_database_migrates_and_rerun_is_a_no_op(): void
    {
        $database = 'hudumadesk_test_migrator';
        $this->createDatabase($database);

        try {
            $db = new Database($this->connectionConfig($database));
            $migrator = new Migrator($db, BASE_PATH . '/db');
            $expected = count($migrator->files());

            $this->assertGreaterThanOrEqual(17, $expected);
            $this->assertSame($expected, $migrator->migrate());
            $this->assertSame(0, $migrator->migrate());

            $status = $migrator->status();
            $this->assertCount($expected, $status);

            foreach ($status as $row) {
                $this->assertTrue($row['applied'], $row['filename'] . ' should be applied.');
                $this->assertFalse($row['modified'], $row['filename'] . ' should not be modified.');
            }

            $ledger = $migrator->appliedLedger();
            $this->assertCount($expected, $ledger);

            $tickets = $db->selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = ?',
                ['tickets'],
            );
            $this->assertSame(1, (int) $tickets['aggregate']);
        } finally {
            $this->dropDatabase($database);
        }
    }

    public function test_changed_migration_is_detected_and_refused(): void
    {
        $database = 'hudumadesk_test_checksum';
        $migrationCopy = BASE_PATH . '/storage/framework/testing/migrations_' . bin2hex(random_bytes(4));
        $this->createDatabase($database);

        try {
            mkdir($migrationCopy, 0775, true);

            foreach (glob(BASE_PATH . '/db/*.sql') ?: [] as $file) {
                copy($file, $migrationCopy . '/' . basename($file));
            }

            $db = new Database($this->connectionConfig($database));
            $migrator = new Migrator($db, $migrationCopy);
            $this->assertSame(count($migrator->files()), $migrator->migrate());

            $first = array_key_first($migrator->files());
            file_put_contents($migrationCopy . '/' . $first, "\n-- changed after apply\n", FILE_APPEND);

            $modified = array_values(array_filter(
                $migrator->status(),
                static fn (array $row): bool => $row['modified'],
            ));
            $this->assertCount(1, $modified);
            $this->assertSame($first, $modified[0]['filename']);

            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Checksum mismatch');

            $migrator->migrate();
        } finally {
            $this->dropDatabase($database);

            if (is_dir($migrationCopy)) {
                foreach (glob($migrationCopy . '/*') ?: [] as $file) {
                    unlink($file);
                }

                rmdir($migrationCopy);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $database): array
    {
        $config = $this->app->container()->get(Config::class)
            ->get('database.connections.mysql');
        $config['database'] = $database;

        return $config;
    }

    private function createDatabase(string $database): void
    {
        $this->guardDatabaseName($database);
        $this->serverPdo()->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        $this->serverPdo()->exec(
            'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
    }

    private function dropDatabase(string $database): void
    {
        $this->guardDatabaseName($database);
        $this->serverPdo()->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    private function guardDatabaseName(string $database): void
    {
        if (!str_starts_with($database, 'hudumadesk_test') || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new \RuntimeException("Refusing to manage database [$database].");
        }
    }

    private function serverPdo(): PDO
    {
        $config = $this->connectionConfig('information_schema');

        return new PDO(
            sprintf('mysql:host=%s;port=%d', $config['host'], $config['port']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
