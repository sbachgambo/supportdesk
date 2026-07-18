<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Db (§10.1) — the ONLY class that touches PDO. Enforced by StaticSuite.
 *
 * Every query is a prepared statement with bound parameters. Column/table names
 * are never taken from user input; dynamic sort/filter columns come from
 * hardcoded allowlists in the calling layer, never from the request.
 *
 * PDO options are mandatory and set in exactly one place here:
 *   ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false, STRINGIFY_FETCHES=false.
 * Emulation OFF is critical — it removes an injection edge case and makes
 * `LIMIT ?` require an explicit PARAM_INT bind (see bindInt()).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::string('DB_HOST', 'localhost'),
            Config::int('DB_PORT', 3306),
            Config::string('DB_NAME'),
            Config::string('DB_CHARSET', 'utf8mb4')
        );

        self::$pdo = new PDO($dsn, Config::string('DB_USER'), Config::string('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        return self::$pdo;
    }

    /** For tests: inject an already-built PDO (e.g. the test DB). */
    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** Run a prepared statement and return it. */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($param, $value, self::paramType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    /** First matching row, or null. */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All matching rows. */
    public static function queryAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Single scalar from the first column of the first row, or null. */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Insert an associative array. Column names come from the caller (developer
     * code), never from user input, and are backtick-quoted. Returns lastInsertId.
     */
    public static function insert(string $table, array $data): string
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $cols);
        $quoted = array_map(static fn(string $c): string => '`' . str_replace('`', '', $c) . '`', $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            str_replace('`', '', $table),
            implode(', ', $quoted),
            implode(', ', $placeholders)
        );
        $params = [];
        foreach ($data as $c => $v) {
            $params[':' . $c] = $v;
        }
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }

    /**
     * Update rows matching a WHERE clause (with its own bound params).
     * $where is a fixed SQL fragment written by developer code, e.g. 'id = :id'.
     * Returns affected row count.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $c => $v) {
            $col = str_replace('`', '', (string) $c);
            $sets[] = "`{$col}` = :set_{$col}";
            $params[":set_{$col}"] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            str_replace('`', '', $table),
            implode(', ', $sets),
            $where
        );
        return self::query($sql, $params)->rowCount();
    }

    /** Delete rows matching a WHERE clause. Returns affected row count. */
    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', str_replace('`', '', $table), $where);
        return self::query($sql, $whereParams)->rowCount();
    }

    /** Run a callable inside a transaction; rolls back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connect();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bind an integer explicitly. With emulation off, `LIMIT ?` and `OFFSET ?`
     * MUST be PARAM_INT or MySQL throws — pagination depends on this (§10.1).
     * Use via query() by wrapping ints you know are LIMIT/OFFSET, or pass through
     * a PDOStatement directly when needed.
     */
    public static function bindInt(PDOStatement $stmt, int|string $param, int $value): void
    {
        $stmt->bindValue($param, $value, PDO::PARAM_INT);
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}
