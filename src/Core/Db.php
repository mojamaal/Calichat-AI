<?php
declare(strict_types=1);
namespace CaliChat\Core;

use PDO;
use PDOStatement;

class Db {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (!self::$pdo) {
            $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME', 'calichat_ai') . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }
    public static function reset(): void { self::$pdo = null; }
    public static function q(string $sql, array $p = []): PDOStatement {
        $s = self::pdo()->prepare($sql);
        $s->execute(array_values($p));
        return $s;
    }
    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public static function val(string $sql, array $p = []) { $r = self::q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    public static function insert(string $table, array $data): int {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
        $ph = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO `$table` ($cols) VALUES ($ph)", array_values($data));
        return (int)self::pdo()->lastInsertId();
    }
    public static function update(string $table, array $data, string $where, array $wp = []): int {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
        return self::q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $wp))->rowCount();
    }
    /** Named advisory lock (per DB connection). Returns true when acquired. */
    public static function lock(string $name, int $timeout = 10): bool {
        return (int)self::val("SELECT GET_LOCK(?, ?)", [substr('cc:' . $name, 0, 60), $timeout]) === 1;
    }
    public static function unlock(string $name): void { self::val("SELECT RELEASE_LOCK(?)", [substr('cc:' . $name, 0, 60)]); }
}
