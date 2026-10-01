<?php
declare(strict_types=1);

namespace Saqf\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper: prepared statements everywhere, exceptions on error. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('SAQF_DB_HOST', '127.0.0.1'),
                Config::get('SAQF_DB_PORT', '3306'),
                Config::get('SAQF_DB_NAME', 'saqf')
            );
            self::$pdo = new PDO($dsn, (string) Config::get('SAQF_DB_USER', 'root'), (string) Config::get('SAQF_DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            try {
                // Keep database NOW()/CURDATE() in the application's timezone (Asia/Riyadh by default).
                self::$pdo->exec("SET time_zone = '" . date('P') . "'");
            } catch (\Throwable $e) {
                // Some MySQL-compatible engines do not support session time zones; PHP time is authoritative.
            }
        }
        return self::$pdo;
    }

    public static function reset(?PDO $pdo = null): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params) === $params ? $params : $params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** First column of first row. */
    public static function val(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** First column of every row. */
    public static function col(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values(array_map([self::class, 'normalize'], $data)));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($data)));
        return self::exec(
            "UPDATE `$table` SET $sets WHERE $where",
            array_merge(array_values(array_map([self::class, 'normalize'], $data)), $whereParams)
        );
    }

    /** IN (?, ?, ?) placeholder helper. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function normalize($v)
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        return $v;
    }
}
