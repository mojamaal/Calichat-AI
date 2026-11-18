<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Net;
use CaliChat\Core\Tenant;

/** In-app alerts plus optional outbound notification (webhook for Slack/Teams/n8n, or e-mail). */
class Notifier {
    public static function alert(?int $tenantId, string $severity, string $type, string $message, ?string $dedupe = null, int $dedupeHours = 6): void {
        try {
            if ($dedupe !== null) {
                $dup = Db::val("SELECT id FROM alerts WHERE (tenant_id <=> ?) AND dedupe_key=? AND resolved_at IS NULL AND created_at > NOW() - INTERVAL ? HOUR LIMIT 1", [$tenantId, $dedupe, $dedupeHours]);
                if ($dup) return;
            }
            Db::insert('alerts', ['tenant_id' => $tenantId, 'severity' => $severity, 'type' => mb_substr($type, 0, 40), 'message' => mb_substr($message, 0, 500), 'dedupe_key' => $dedupe ? mb_substr($dedupe, 0, 120) : null]);
            Log::write($severity === 'crit' ? 'error' : 'warn', 'alert:' . $type, ['tenant' => $tenantId, 'alert' => $message]);
            if ($tenantId && $severity !== 'info') self::push($tenantId, $severity, $type, $message);
        } catch (\Throwable $e) { Log::exception($e, 'alert_failed'); }
    }

    private static function push(int $tenantId, string $sev, string $type, string $msg): void {
        $t = Tenant::get($tenantId); if (!$t) return;
        $hook = (string)($t['cfg']['notify_webhook'] ?? '');
        if ($hook !== '' && preg_match('#^https://#i', $hook)) {
            Net::request('POST', $hook, ['Content-Type: application/json'], [
                'timeout' => 5, 'max_bytes' => 4096,
                'body' => json_encode(['text' => "[CaliChat · {$t['name']}] " . strtoupper($sev) . " {$type}: {$msg}", 'tenant' => $t['name'], 'severity' => $sev, 'type' => $type, 'message' => $msg], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $to = (string)($t['cfg']['notify_email'] ?? '');
        if ($to !== '' && env('MAIL_ENABLED', '0') === '1' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            @mail($to, "[CaliChat] {$type}: " . mb_substr($msg, 0, 60), $msg, 'From: ' . env('MAIL_FROM', 'noreply@localhost'));
        }
    }

    public static function unseenCount(int $tenantId): int {
        return (int)Db::val("SELECT COUNT(*) FROM alerts WHERE tenant_id=? AND resolved_at IS NULL AND seen=0", [$tenantId]);
    }
}
