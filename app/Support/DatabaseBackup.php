<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use PDO;
use RuntimeException;

/**
 * Streams backups through the MariaDB client tools so large databases never
 * have to fit in memory. The restore path is exercised by verify(), which
 * restores into a throwaway <database>_restore_test schema and compares rows.
 */
final class DatabaseBackup
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{path: string, bytes: int, database: string}
     */
    public function dump(?string $path = null): array
    {
        $connection = $this->connection();
        $database = (string) $connection['database'];
        $directory = (string) $this->config->get('backup.directory');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the backup directory.');
        }

        $path ??= $directory . '/' . $database . '_' . gmdate('Ymd_His') . '.sql.gz';

        if (!str_ends_with(strtolower($path), '.gz')) {
            throw new RuntimeException('Backup paths must end with .gz.');
        }

        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the backup file for writing.');
        }

        $command = [
            $this->binary('mysqldump'),
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--user=' . $connection['username'],
            '--default-character-set=' . $connection['charset'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            $database,
        ];

        $pipes = [];

        try {
            $process = $this->start($command, $pipes);
            fclose($pipes[0]);

            stream_copy_to_stream($pipes[1], $handle);
            fclose($pipes[1]);
            gzclose($handle);
            $handle = null;

            $error = trim(stream_get_contents($pipes[2]) ?: '');
            fclose($pipes[2]);

            if ($this->finish($process) !== 0) {
                throw new RuntimeException('mysqldump failed: ' . $this->shortError($error));
            }
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                gzclose($handle);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            @unlink($path);

            throw $exception;
        }

        @chmod($path, 0600);

        return ['path' => $path, 'bytes' => (int) filesize($path), 'database' => $database];
    }

    /**
     * @return array{tables: int, rows: int}
     */
    public function restore(string $path, string $database, bool $createIfMissing = false): array
    {
        $this->assertDatabaseName($database);

        if (!is_file($path)) {
            throw new RuntimeException('Backup file not found: ' . $path);
        }

        if ($createIfMissing) {
            $this->createDatabase($database);
        }

        $connection = $this->connection();
        $command = [
            $this->binary('mysql'),
            '--host=' . $connection['host'],
            '--port=' . $connection['port'],
            '--user=' . $connection['username'],
            '--default-character-set=' . $connection['charset'],
            '--database=' . $database,
        ];

        $pipes = [];
        $process = $this->start($command, $pipes);
        $input = str_ends_with($path, '.gz') ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($input === false) {
            throw new RuntimeException('Unable to read the backup file.');
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);

        $error = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[2]);
        $status = $this->finish($process);

        if ($status !== 0) {
            throw new RuntimeException('mysql restore failed: ' . $this->shortError($error));
        }

        $counts = $this->tableCounts($database);

        return ['tables' => count($counts), 'rows' => array_sum($counts)];
    }

    /**
     * Dumps the configured database, restores it into <database>_restore_test,
     * compares every table's row count, then drops the scratch schema.
     *
     * @return array{path: string, bytes: int, matches: bool, tables: array<string, array{source: int, restored: int}>}
     */
    public function verify(?string $path = null): array
    {
        $dump = $this->dump($path);
        $database = $dump['database'];
        $target = $database . '_restore_test';

        $this->dropDatabase($target);

        try {
            $this->restore($dump['path'], $target, true);
            $source = $this->tableCounts($database);
            $restored = $this->tableCounts($target);
            $tables = [];
            $matches = array_keys($source) === array_keys($restored);

            foreach ($source as $table => $count) {
                $tables[$table] = ['source' => $count, 'restored' => $restored[$table] ?? -1];
                $matches = $matches && $tables[$table]['source'] === $tables[$table]['restored'];
            }
        } finally {
            $this->dropDatabase($target);
        }

        return [
            'path' => $dump['path'],
            'bytes' => $dump['bytes'],
            'matches' => $matches,
            'tables' => $tables,
        ];
    }

    public function prune(int $days): int
    {
        $connection = $this->connection();
        $pattern = (string) $this->config->get('backup.directory')
            . '/' . $connection['database'] . '_*.sql.gz';
        $cutoff = time() - (max(1, $days) * 86400);
        $deleted = 0;

        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function binaryAvailable(): bool
    {
        try {
            $this->binary('mysqldump');
            $this->binary('mysql');

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}
     */
    private function connection(): array
    {
        /** @var array<string, mixed> $connection */
        $connection = $this->config->get('database.connections.mysql');

        return [
            'host' => (string) $connection['host'],
            'port' => (int) $connection['port'],
            'database' => (string) $connection['database'],
            'username' => (string) $connection['username'],
            'password' => (string) $connection['password'],
            'charset' => (string) ($connection['charset'] ?? 'utf8mb4'),
        ];
    }

    private function binary(string $name): string
    {
        $configured = (string) $this->config->get('backup.' . $name . '_bin', '');

        if ($configured !== '') {
            if (is_file($configured) || $this->onPath($configured)) {
                return $configured;
            }

            throw new RuntimeException($name . ' binary not found: ' . $configured);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $xampp = 'C:/xampp/mysql/bin/' . $name . '.exe';

            if (is_file($xampp)) {
                return $xampp;
            }
        }

        if (!$this->onPath($name)) {
            throw new RuntimeException("The $name client is not on PATH. Set MYSQLDUMP_BIN and MYSQL_BIN.");
        }

        return $name;
    }

    private function onPath(string $binary): bool
    {
        $path = getenv('PATH') ?: '';

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary;

            if (is_file($candidate) || is_file($candidate . '.exe')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     * @return resource
     */
    private function start(array $command, array &$pipes)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = array_merge(getenv() ?: [], [
            'MYSQL_PWD' => $this->connection()['password'],
        ]);

        $process = proc_open($command, $descriptors, $pipes, null, $environment);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the database client.');
        }

        return $process;
    }

    /**
     * @param resource $process
     */
    private function finish($process): int
    {
        return proc_close($process);
    }

    private function shortError(string $error): string
    {
        $line = preg_split('/\r?\n/', trim($error))[0] ?? 'unknown error';

        return mb_substr($line, 0, 300);
    }

    private function createDatabase(string $database): void
    {
        $this->assertDatabaseName($database);
        $this->pdo(null)->exec(
            'CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
    }

    private function dropDatabase(string $database): void
    {
        $this->assertDatabaseName($database);
        $this->pdo(null)->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(string $database): array
    {
        $this->assertDatabaseName($database);
        $pdo = $this->pdo($database);

        $tables = $pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
        );
        $tables->execute([$database]);
        $counts = [];

        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $name = (string) $table;

            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                throw new RuntimeException('Unexpected table name in backup: ' . $name);
            }

            $counts[$name] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
        }

        return $counts;
    }

    private function pdo(?string $database): PDO
    {
        $connection = $this->connection();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s%s',
            $connection['host'],
            $connection['port'],
            $connection['charset'],
            $database === null ? '' : ';dbname=' . $database,
        );

        return new PDO($dsn, $connection['username'], $connection['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function assertDatabaseName(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Refusing an unsafe database name: ' . $database);
        }
    }
}
