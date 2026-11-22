<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO wrapper: prepared statements only, no string interpolation of
 * values, dialect aware (MariaDB in production, SQLite for the local demo and
 * the test-suite). Every monetary column is an integer (cents).
 */
final class Database
{
    private PDO $pdo;
    private int $depth = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $driver = $config['driver'] ?? 'mysql';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $path = $config['sqlite_path'] ?? ':memory:';
            $dir = dirname($path);
            if ($path !== ':memory:' && !is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 3306),
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    /** @param array<string|int,mixed> $bind */
    public function select(string $sql, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize($bind));

        return $statement->fetchAll();
    }

    /** @param array<string|int,mixed> $bind */
    public function selectOne(string $sql, array $bind = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize($bind));
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $bind */
    public function value(string $sql, array $bind = []): mixed
    {
        $row = $this->selectOne($sql, $bind);
        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    /** @param array<string|int,mixed> $bind */
    public function execute(string $sql, array $bind = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize($bind));

        return $statement->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            implode(', ', array_map([$this, 'quote'], $columns)),
            implode(', ', $placeholders)
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalize($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $bind
     */
    public function update(string $table, array $data, string $where, array $bind = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $this->quote($column) . ' = :' . $column;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quote($table), implode(', ', $sets), $where);
        $statement = $this->pdo->prepare($sql);

        return (int) $statement->execute($this->normalize(array_merge($data, $bind)));
    }

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec($this->isSqlite() ? 'SAVEPOINT sp' . $this->depth : 'SAVEPOINT sp' . $this->depth);
        }
        $this->depth++;
    }

    public function commit(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT sp' . $this->depth);
        }
    }

    public function rollBack(): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp' . $this->depth);
        }
    }

    /**
     * Runs $fn inside a transaction. Nested calls use savepoints. The callback
     * receives this connection. Row locking belongs inside the callback via
     * lockClause() on the SELECT that reads the balance/sequence.
     */
    public function transaction(callable $fn): mixed
    {
        $this->begin();
        try {
            $result = $fn($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /** MySQL: SELECT ... FOR UPDATE. SQLite serialises writers, so no clause. */
    public function lockClause(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @param array<string|int,mixed> $bind */
    private function normalize(array $bind): array
    {
        $normalized = [];
        foreach ($bind as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            if (is_bool($value)) {
                $normalized[$name] = $value ? 1 : 0;
            } elseif ($value instanceof \DateTimeInterface) {
                $normalized[$name] = $value->format('Y-m-d H:i:s');
            } else {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }

    public static function connect(): self
    {
        try {
            return new self((array) Config::get('database', []));
        } catch (PDOException $e) {
            Logger::error('database.connection_failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
