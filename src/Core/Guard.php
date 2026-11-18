<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Rate limiter (fixed window) and circuit breaker, both persisted in MySQL so every worker/process shares them. */
class Guard {
    /** Returns true if the call is allowed (and counts it). */
    public static function allow(string $bucket, int $limit, int $windowSec = 60): bool {
        $w = (int)(floor(time() / $windowSec) * $windowSec);
        Db::q("INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1", [$bucket, $w]);
        $hits = (int)Db::val("SELECT hits FROM rate_limits WHERE bucket=? AND window_start=?", [$bucket, $w]);
        return $hits <= $limit;
    }
    public static function secondsToWindowEnd(int $windowSec = 60): int { return $windowSec - (time() % $windowSec); }

    public static function circuitOpen(string $name): bool {
        $c = Db::one("SELECT open_until FROM circuits WHERE name=?", [$name]);
        return $c && $c['open_until'] && strtotime($c['open_until'] . ' UTC') > time();
    }
    public static function circuitSuccess(string $name): void {
        Db::q("INSERT INTO circuits (name, failures, open_until, last_error, updated_at) VALUES (?,0,NULL,NULL,NOW()) ON DUPLICATE KEY UPDATE failures=0, open_until=NULL, updated_at=NOW()", [$name]);
    }
    /** @return bool true when this failure just opened the circuit */
    public static function circuitFailure(string $name, string $error, int $threshold = 5, int $cooldown = 60): bool {
        Db::q("INSERT INTO circuits (name, failures, last_error, updated_at) VALUES (?,1,?,NOW()) ON DUPLICATE KEY UPDATE failures=failures+1, last_error=VALUES(last_error), updated_at=NOW()", [$name, mb_substr($error, 0, 290)]);
        $f = (int)Db::val("SELECT failures FROM circuits WHERE name=?", [$name]);
        if ($f >= $threshold && !self::circuitOpen($name)) {
            Db::q("UPDATE circuits SET open_until = NOW() + INTERVAL ? SECOND, failures = 0 WHERE name=?", [$cooldown, $name]);
            return true;
        }
        return false;
    }
}
