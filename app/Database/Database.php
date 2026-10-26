<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset'],
        );

        $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->connection()->prepare($sql);

        foreach (array_values($bindings) as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            );
        }

        $statement->execute();

        return $statement;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function name(): string
    {
        return $this->config['database'];
    }
}
