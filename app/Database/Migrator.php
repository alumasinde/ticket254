<?php

declare(strict_types=1);

namespace App\Database;

final class Migrator
{
    private const LEDGER = 'schema_migrations';

    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @param null|callable(string, int, int): void $onApplied
     */
    public function migrate(?callable $onApplied = null): int
    {
        $this->ensureLedger();

        $files = $this->files();
        $applied = $this->appliedLedger();
        $pending = [];

        foreach ($files as $filename => $path) {
            $checksum = $this->checksum($path);

            if (isset($applied[$filename])) {
                if ($applied[$filename]['checksum'] !== $checksum) {
                    throw new MigrationException(
                        "Checksum mismatch for migration [$filename]. Applied migrations must not change.",
                    );
                }

                continue;
            }

            $pending[$filename] = $path;
        }

        $total = count($pending);
        $count = 0;

        // MySQL DDL auto-commits; a failed file is not recorded and must be fixed forward.
        foreach ($pending as $filename => $path) {
            foreach (SqlSplitter::split((string) file_get_contents($path)) as $statement) {
                $this->db->connection()->exec($statement);
            }

            $this->db->statement(
                'INSERT INTO ' . self::LEDGER . ' (filename, checksum, applied_at) VALUES (?, ?, ?)',
                [$filename, $this->checksum($path), gmdate('Y-m-d H:i:s')],
            );

            $count++;

            if ($onApplied !== null) {
                $onApplied($filename, $count, $total);
            }
        }

        return $count;
    }

    /**
     * @return list<array{filename: string, checksum: string, applied: bool, applied_at: ?string, modified: bool}>
     */
    public function status(): array
    {
        $applied = $this->ledgerExists() ? $this->appliedLedger() : [];
        $status = [];

        foreach ($this->files() as $filename => $path) {
            $checksum = $this->checksum($path);
            $isApplied = isset($applied[$filename]);

            $status[] = [
                'filename' => $filename,
                'checksum' => $checksum,
                'applied' => $isApplied,
                'applied_at' => $isApplied ? $applied[$filename]['applied_at'] : null,
                'modified' => $isApplied && $applied[$filename]['checksum'] !== $checksum,
            ];
        }

        return $status;
    }

    /**
     * @return array<string, array{checksum: string, applied_at: string}>
     */
    public function appliedLedger(): array
    {
        $rows = $this->db->select('SELECT filename, checksum, applied_at FROM ' . self::LEDGER . ' ORDER BY filename');
        $ledger = [];

        foreach ($rows as $row) {
            $ledger[(string) $row['filename']] = [
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $ledger;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $map = [];

        foreach ($files as $path) {
            $map[basename($path)] = $path;
        }

        return $map;
    }

    private function ensureLedger(): void
    {
        $this->db->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LEDGER . ' ('
            . 'filename VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'checksum CHAR(64) NOT NULL, '
            . 'applied_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function ledgerExists(): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?',
            [self::LEDGER],
        );

        return (int) ($row['aggregate'] ?? 0) > 0;
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw new MigrationException("Unable to read migration [$path].");
        }

        return $checksum;
    }
}
