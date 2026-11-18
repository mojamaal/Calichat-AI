<?php
declare(strict_types=1);
namespace CaliChat\Core;

class Tenant {
    private static array $tzCache = [];

    public static function get(int $id): ?array {
        $t = Db::one("SELECT * FROM tenants WHERE id=?", [$id]);
        if ($t) $t['cfg'] = json_decode((string)$t['settings'], true) ?: [];
        return $t;
    }
    public static function saveSettings(int $id, array $patch): void {
        $t = self::get($id);
        $cfg = array_merge($t['cfg'] ?? [], $patch);
        Db::update('tenants', ['settings' => json_encode($cfg, JSON_UNESCAPED_UNICODE)], 'id=?', [$id]);
    }
    public static function geminiKey(array $tenant): string {
        if (env('GEMINI_DISABLED', '0') === '1') return ''; // kill switch: no AI calls at all (used by the automated tests)
        $k = Crypto::decrypt($tenant['cfg']['gemini_key'] ?? null);
        return $k ?: (string)env('GEMINI_API_KEY', '');
    }

    public static function tz(int $tid): string {
        if (!isset(self::$tzCache[$tid])) self::$tzCache[$tid] = (string)(Db::val("SELECT timezone FROM tenants WHERE id=?", [$tid]) ?: 'UTC');
        return self::$tzCache[$tid];
    }
    /** Tenant-local calendar date (Y-m-d) for "now" plus an optional day offset. */
    public static function localDate(string $tz, int $offsetDays = 0): string {
        $d = new \DateTime('now', new \DateTimeZone($tz)); if ($offsetDays) $d->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' day');
        return $d->format('Y-m-d');
    }
    /** UTC [start,end) for a tenant-local date. */
    public static function dayRangeUtc(string $tz, string $localDate): array {
        $s = new \DateTime($localDate . ' 00:00:00', new \DateTimeZone($tz)); $e = clone $s; $e->modify('+1 day');
        $u = new \DateTimeZone('UTC');
        return [$s->setTimezone($u)->format('Y-m-d H:i:s'), $e->setTimezone($u)->format('Y-m-d H:i:s')];
    }
    public static function monthStart(string $tz): string { return substr(self::localDate($tz), 0, 8) . '01'; }

    /** Increment a per-day counter in the tenant's local day. */
    public static function usage(int $tid, string $field, int $by = 1): void {
        $allowed = ['messages_in', 'messages_out', 'ai_replies', 'handoffs', 'tool_calls', 'tokens_in', 'tokens_out', 'ai_calls', 'ai_errors', 'latency_ms_total', 'media_in', 'test_tokens'];
        if (!in_array($field, $allowed, true) || $by === 0) return;
        $day = self::localDate(self::tz($tid));
        Db::q("INSERT INTO usage_daily (tenant_id, day, `$field`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `$field` = `$field` + VALUES(`$field`)", [$tid, $day, $by]);
    }
    public static function monthMessages(int $tid): int {
        return (int)Db::val("SELECT COALESCE(SUM(ai_replies),0) FROM usage_daily WHERE tenant_id=? AND day >= ?", [$tid, self::monthStart(self::tz($tid))]);
    }
    public static function monthTokens(int $tid): int {
        return (int)Db::val("SELECT COALESCE(SUM(tokens_in+tokens_out+test_tokens),0) FROM usage_daily WHERE tenant_id=? AND day >= ?", [$tid, self::monthStart(self::tz($tid))]);
    }
}
