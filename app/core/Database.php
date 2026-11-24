<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper.
 * - Real prepared statements (emulation off): values never become part of the SQL text.
 * - Table and column names passed to insert()/update() must come from code, never from user input.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $overrideName = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = config('database');
            $name = self::$overrideName ?? $cfg['name'];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $name);
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Bangladesh time (UTC+6, no daylight saving) so NOW() matches PHP's date().
            self::$pdo->exec("SET time_zone = '+06:00'");
        }
        return self::$pdo;
    }

    /** Tests use a separate database. */
    public static function useDatabase(?string $name): void
    {
        self::$overrideName = $name;
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data column => value */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data  column => value
     * @param array<string, mixed> $whereParams named params used in $where (prefix them with w_)
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = $column . ' = :s_' . $column;
            $params['s_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::run($sql, $params + $whereParams)->rowCount();
    }

    /**
     * Runs $fn inside a transaction. Rolls back on any exception.
     * Deadlocks (two customers buying the same items at the same moment) are retried.
     */
    public static function transaction(callable $fn, int $attempts = 3): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        for ($try = 1; ; $try++) {
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $deadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1213, 1205], true);
                if ($deadlock && $try < $attempts) {
                    usleep(random_int(20000, 120000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Builds "?, ?, ?" for IN (...) lists. */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
