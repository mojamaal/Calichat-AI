<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Minimal forward-only SQL migration runner. Files: database/migrations/NNN_name.sql, statements end with ";" at end of line. */
class Migrator {
    public static function run(\PDO $pdo, ?callable $say = null): int {
        $say = $say ?: function ($m) {};
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(\PDO::FETCH_COLUMN);
        $files = glob(ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $n = 0;
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) continue;
            $sql = preg_replace('/^\s*--.*$/m', '', preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f)));
            $stmts = preg_split('/;\s*\n/', $sql . "\n");
            foreach ($stmts as $s) {
                $s = trim($s, " \t\r\n;");
                if ($s === '') continue;
                try { $pdo->exec($s); }
                catch (\PDOException $e) { throw new \RuntimeException("Migration $name failed: " . $e->getMessage() . "\n  SQL: " . substr($s, 0, 160), 0, $e); }
            }
            $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")->execute([$name]);
            $say("applied $name");
            $n++;
        }
        return $n;
    }
}
