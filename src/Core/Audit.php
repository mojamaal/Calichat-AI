<?php
declare(strict_types=1);
namespace CaliChat\Core;

/** Append-only audit trail of security-relevant actions. */
class Audit {
    public static function log(string $action, string $target = '', string $detail = '', ?int $tenantId = null, ?int $userId = null): void {
        try {
            $u = $userId === null ? Auth::user() : null;
            Db::insert('audit_log', [
                'tenant_id' => $tenantId ?? (Auth::tenantId() ?: null),
                'user_id' => $userId ?? ($u['id'] ?? null),
                'action' => mb_substr($action, 0, 60), 'target' => mb_substr($target, 0, 120) ?: null,
                'detail' => mb_substr($detail, 0, 1000) ?: null, 'ip' => client_ip(),
            ]);
        } catch (\Throwable $e) { Log::exception($e, 'audit_failed'); }
    }
}
