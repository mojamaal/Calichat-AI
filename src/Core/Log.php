<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Structured JSON-lines logging with a per-request id (storage/logs/app-YYYYMMDD.log). */
class Log {
    private static string $rid = '';
    public static array $ctx = [];

    public static function init(): void {
        $h = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        self::$rid = (is_string($h) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $h)) ? $h : bin2hex(random_bytes(8));
    }
    public static function requestId(): string { return self::$rid ?: (self::$rid = bin2hex(random_bytes(8))); }
    public static function with(array $ctx): void { self::$ctx = array_merge(self::$ctx, $ctx); }

    public static function write(string $level, string $msg, array $extra = []): void {
        $line = json_encode(['t' => gmdate('c'), 'lvl' => $level, 'rid' => self::requestId(), 'msg' => $msg] + self::$ctx + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $dir = ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/app-' . gmdate('Ymd') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
    public static function info(string $m, array $c = []): void { self::write('info', $m, $c); }
    public static function warn(string $m, array $c = []): void { self::write('warn', $m, $c); }
    public static function error(string $m, array $c = []): void { self::write('error', $m, $c); }
    public static function exception(\Throwable $e, string $m = 'exception', array $c = []): void {
        self::write('error', $m, ['err' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()] + $c);
    }
}
