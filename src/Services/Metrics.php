<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Core\Tenant;

/** Read-side metrics for the tenant "Usage & Monitoring" page and the platform "Operations" page. */
class Metrics {
    public static function cost(int $tin, int $tout): float {
        return ($tin * (float)env('GEMINI_PRICE_IN_PER_M', 0.10) + $tout * (float)env('GEMINI_PRICE_OUT_PER_M', 0.40)) / 1_000_000;
    }

    private static function pctile(string $where, array $p, float $q): ?int {
        $n = (int)Db::val("SELECT COUNT(*) FROM ai_calls WHERE $where", $p);
        if ($n === 0) return null;
        $off = min($n - 1, (int)floor($q * $n));
        $v = Db::val("SELECT latency_ms FROM ai_calls WHERE $where ORDER BY latency_ms LIMIT 1 OFFSET $off", $p);
        return $v === null ? null : (int)$v;
    }

    /** @return array<string,mixed> */
    public static function tenant(int $tid, int $days = 14): array {
        $days = max(1, min(90, $days));
        $t = Tenant::get($tid);
        $tz = $t['timezone'];
        $from = Tenant::localDate($tz, -($days - 1)); $today = Tenant::localDate($tz);
        [$fromUtc] = Tenant::dayRangeUtc($tz, $from);
        $m = [];

        // daily series (tenant-local days)
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) $series[Tenant::localDate($tz, -$i)] = ['messages_in' => 0, 'messages_out' => 0, 'ai_replies' => 0, 'handoffs' => 0, 'tool_calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'ai_calls' => 0, 'ai_errors' => 0, 'latency_ms_total' => 0, 'media_in' => 0, 'test_tokens' => 0];
        foreach (Db::all("SELECT * FROM usage_daily WHERE tenant_id=? AND day >= ?", [$tid, $from]) as $r) if (isset($series[$r['day']])) foreach ($series[$r['day']] as $k => $_) $series[$r['day']][$k] = (int)$r[$k];
        $m['series'] = $series;
        $sum = array_fill_keys(array_keys(reset($series)), 0);
        foreach ($series as $row) foreach ($row as $k => $v) $sum[$k] += $v;
        $m['sum'] = $sum;
        $m['cost'] = self::cost($sum['tokens_in'], $sum['tokens_out']);
        $m['test_cost'] = self::cost($sum['test_tokens'], 0);

        // AI latency and quality
        $w = "tenant_id=? AND created_at >= ? AND is_test=0"; $p = [$tid, $fromUtc];
        $m['ai'] = ['p50' => self::pctile($w . " AND ok=1 AND kind='reply'", $p, .5), 'p95' => self::pctile($w . " AND ok=1 AND kind='reply'", $p, .95),
            'error_rate' => $sum['ai_calls'] > 0 ? $sum['ai_errors'] / $sum['ai_calls'] : 0.0,
            'avg_ms' => $sum['ai_calls'] > 0 ? (int)($sum['latency_ms_total'] / $sum['ai_calls']) : 0];
        $m['by_model'] = Db::all("SELECT model, kind, COUNT(*) calls, SUM(ok=0) errors, ROUND(AVG(latency_ms)) avg_ms, SUM(tokens_in) tin, SUM(tokens_out) tout FROM ai_calls WHERE $w GROUP BY model, kind ORDER BY calls DESC", $p);
        $m['truncated'] = (int)Db::val("SELECT COUNT(*) FROM ai_calls WHERE $w AND finish_reason='MAX_TOKENS'", $p);

        // tools
        $m['tools'] = Db::all("SELECT tool, COUNT(*) calls, SUM(ok) ok, ROUND(AVG(latency_ms)) avg_ms, MAX(latency_ms) max_ms, SUM(cache_hit) cached FROM tool_logs WHERE tenant_id=? AND created_at >= ? GROUP BY tool ORDER BY calls DESC", [$tid, $fromUtc]);

        // conversations and handoffs (real chats only)
        $m['convs'] = (int)Db::val("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND is_test=0 AND created_at >= ?", [$tid, $fromUtc]);
        $m['convs_handoff'] = (int)Db::val("SELECT COUNT(DISTINCT h.conversation_id) FROM handoffs h JOIN conversations c ON c.id=h.conversation_id WHERE h.tenant_id=? AND c.is_test=0 AND h.created_at >= ?", [$tid, $fromUtc]);
        $m['containment'] = $m['convs'] > 0 ? max(0, ($m['convs'] - $m['convs_handoff']) / $m['convs']) : null;
        $m['handoff_wait_min'] = Db::val("SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) FROM handoffs WHERE tenant_id=? AND resolved_at IS NOT NULL AND created_at >= ?", [$tid, $fromUtc]);
        $m['open_handoffs'] = (int)Db::val("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND status='human' AND is_test=0", [$tid]);
        $m['new_contacts'] = (int)Db::val("SELECT COUNT(*) FROM contacts WHERE tenant_id=? AND channel_type<>'playground' AND created_at >= ?", [$tid, $fromUtc]);

        // busy hours in tenant time
        $off = (new \DateTime('now', new \DateTimeZone($tz)))->getOffset() / 3600;
        $hours = array_fill(0, 24, 0);
        foreach (Db::all("SELECT HOUR(created_at) h, COUNT(*) n FROM messages WHERE tenant_id=? AND direction='in' AND created_at >= ? GROUP BY h", [$tid, $fromUtc]) as $r) $hours[(((int)$r['h'] + (int)round($off)) % 24 + 24) % 24] += (int)$r['n'];
        $m['hours'] = $hours;

        // queue health
        $m['queue'] = [
            'pending' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE tenant_id=? AND status IN ('received','retry','processing')", [$tid]),
            'oldest_s' => (int)Db::val("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(received_at), NOW()),0) FROM inbound_events WHERE tenant_id=? AND status IN ('received','retry') AND next_attempt_at <= NOW()", [$tid]),
            'failed' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE tenant_id=? AND status='failed'", [$tid]),
            'processed' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE tenant_id=? AND status IN ('done','skipped') AND received_at >= ?", [$tid, $fromUtc]),
            'avg_lag_s' => Db::val("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, received_at, processed_at))) FROM inbound_events WHERE tenant_id=? AND status='done' AND received_at >= ?", [$tid, $fromUtc]),
        ];
        $m['failed_events'] = Db::all("SELECT id, sender, text, last_error, attempts, received_at, status FROM inbound_events WHERE tenant_id=? AND status IN ('failed') ORDER BY id DESC LIMIT 10", [$tid]);

        // plan usage
        $mStart = Tenant::monthStart($tz);
        $m['plan'] = [
            'replies' => Tenant::monthMessages($tid), 'replies_limit' => (int)$t['monthly_message_limit'],
            'tokens' => Tenant::monthTokens($tid), 'tokens_limit' => (int)$t['monthly_token_limit'],
            'rpm_limit' => (int)$t['llm_rpm'], 'rpm_now' => (int)Db::val("SELECT COALESCE(hits,0) FROM rate_limits WHERE bucket=? AND window_start=?", ['llm:t' . $tid, (int)(floor(time() / 60) * 60)]),
            'month_cost' => (function () use ($tid, $mStart) { $r = Db::one("SELECT COALESCE(SUM(tokens_in),0) a, COALESCE(SUM(tokens_out),0) b FROM usage_daily WHERE tenant_id=? AND day >= ?", [$tid, $mStart]); return self::cost((int)$r['a'], (int)$r['b']); })(),
        ];

        // resources held
        $m['resources'] = [
            'messages' => (int)Db::val("SELECT COUNT(*) FROM messages WHERE tenant_id=?", [$tid]),
            'message_bytes' => (int)Db::val("SELECT COALESCE(SUM(LENGTH(content)),0) FROM messages WHERE tenant_id=?", [$tid]),
            'contacts' => (int)Db::val("SELECT COUNT(*) FROM contacts WHERE tenant_id=?", [$tid]),
            'memories' => (int)Db::val("SELECT COUNT(*) FROM memories WHERE tenant_id=?", [$tid]),
            'chunks' => (int)Db::val("SELECT COUNT(*) FROM knowledge_chunks WHERE tenant_id=?", [$tid]),
            'chunks_embedded' => (int)Db::val("SELECT COUNT(*) FROM knowledge_chunks WHERE tenant_id=? AND embedding IS NOT NULL", [$tid]),
            'tool_logs' => (int)Db::val("SELECT COUNT(*) FROM tool_logs WHERE tenant_id=?", [$tid]),
            'ai_calls' => (int)Db::val("SELECT COUNT(*) FROM ai_calls WHERE tenant_id=?", [$tid]),
            'channels' => (int)Db::val("SELECT COUNT(*) FROM channels WHERE tenant_id=?", [$tid]),
            'tools' => (int)Db::val("SELECT COUNT(*) FROM tools WHERE tenant_id=?", [$tid]),
        ];

        $m['channels'] = Db::all("SELECT id, name, type, status, last_webhook_at, last_error, verified_at, verify_error, access_token IS NOT NULL has_token, app_secret IS NOT NULL has_secret FROM channels WHERE tenant_id=?", [$tid]);
        $m['ai_errors'] = Db::all("SELECT created_at, model, kind, http_status, error FROM ai_calls WHERE tenant_id=? AND ok=0 AND is_test=0 ORDER BY id DESC LIMIT 8", [$tid]);
        $m['tool_errors'] = Db::all("SELECT created_at, tool, http_status, error FROM tool_logs WHERE tenant_id=? AND ok=0 ORDER BY id DESC LIMIT 8", [$tid]);
        $m['kb_misses'] = Db::all("SELECT query, hits, last_seen FROM kb_misses WHERE tenant_id=? ORDER BY hits DESC, last_seen DESC LIMIT 10", [$tid]);
        $m['top_contacts'] = Db::all("SELECT ct.name, ct.phone, ct.external_id, ct.channel_type, COUNT(*) n FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN contacts ct ON ct.id=c.contact_id WHERE m.tenant_id=? AND m.sender='customer' AND m.created_at >= ? AND ct.channel_type<>'playground' GROUP BY ct.id ORDER BY n DESC LIMIT 8", [$tid, $fromUtc]);
        $m['range'] = ['from' => $from, 'to' => $today, 'days' => $days];
        return $m;
    }

    /** @return array<string,mixed> */
    public static function platform(): array {
        $p = [];
        $hb = Db::one("SELECT last_beat, info, TIMESTAMPDIFF(SECOND, last_beat, NOW()) age FROM worker_heartbeats WHERE name='queue'");
        $p['worker'] = ['alive' => $hb && (int)$hb['age'] <= 30, 'age' => $hb ? (int)$hb['age'] : null, 'info' => $hb['info'] ?? null, 'inline_fallback' => env('QUEUE_INLINE_DRAIN', '1') === '1'];
        $p['queue'] = [
            'pending' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE status IN ('received','retry','processing')"),
            'oldest_s' => (int)Db::val("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(received_at), NOW()),0) FROM inbound_events WHERE status IN ('received','retry') AND next_attempt_at <= NOW()"),
            'failed' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE status='failed'"),
            'per_min' => (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE received_at > NOW() - INTERVAL 5 MINUTE") / 5,
        ];
        $w = "created_at > NOW() - INTERVAL 1 HOUR";
        $p['ai_hour'] = ['calls' => (int)Db::val("SELECT COUNT(*) FROM ai_calls WHERE $w"), 'errors' => (int)Db::val("SELECT COUNT(*) FROM ai_calls WHERE $w AND ok=0"),
            'p95' => self::pctile("$w AND ok=1 AND kind='reply'", [], .95), 'p50' => self::pctile("$w AND ok=1 AND kind='reply'", [], .5)];
        $p['circuits'] = Db::all("SELECT name, failures, open_until, last_error, updated_at FROM circuits ORDER BY name");
        $p['rate_global'] = (int)Db::val("SELECT COALESCE(hits,0) FROM rate_limits WHERE bucket='llm:global' AND window_start=?", [(int)(floor(time() / 60) * 60)]);
        $p['rate_global_limit'] = (int)env('GEMINI_GLOBAL_RPM', 600);
        $p['db_bytes'] = (int)Db::val("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()");
        $big = Db::all("SELECT table_name t, (data_length+index_length) b, table_rows r FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY (data_length+index_length) DESC LIMIT 6");
        $p['big_tables'] = $big;
        $logBytes = 0; foreach (glob(ROOT . '/storage/logs/*') ?: [] as $f) $logBytes += (int)@filesize($f);
        $p['log_bytes'] = $logBytes;
        $p['disk_free'] = (int)@disk_free_space(ROOT);

        // per-tenant resource table (aggregated, no N+1)
        $mStart = gmdate('Y-m-01');
        $rows = Db::all("SELECT t.id, t.name, t.status, t.plan, t.monthly_message_limit lim, t.monthly_token_limit tlim, t.llm_rpm, t.timezone,
              COALESCE(u.replies,0) replies, COALESCE(u.tin,0) tin, COALESCE(u.tout,0) tout, COALESCE(u.ttest,0) ttest, COALESCE(u.tools,0) tools
            FROM tenants t LEFT JOIN (SELECT tenant_id, SUM(ai_replies) replies, SUM(tokens_in) tin, SUM(tokens_out) tout, SUM(test_tokens) ttest, SUM(tool_calls) tools FROM usage_daily WHERE day >= ? GROUP BY tenant_id) u ON u.tenant_id=t.id
            ORDER BY replies DESC", [$mStart]);
        $err = []; foreach (Db::all("SELECT tenant_id, COUNT(*) n, SUM(ok=0) bad, ROUND(AVG(latency_ms)) avg_ms FROM ai_calls WHERE created_at > NOW() - INTERVAL 24 HOUR AND is_test=0 GROUP BY tenant_id") as $r) $err[$r['tenant_id']] = $r;
        $pend = []; foreach (Db::all("SELECT tenant_id, SUM(status IN ('received','retry','processing')) pend, SUM(status='failed') failed FROM inbound_events GROUP BY tenant_id") as $r) $pend[$r['tenant_id']] = $r;
        $rowsN = []; foreach (Db::all("SELECT tenant_id, COUNT(*) n FROM messages GROUP BY tenant_id") as $r) $rowsN[$r['tenant_id']] = (int)$r['n'];
        $last = []; foreach (Db::all("SELECT tenant_id, MAX(created_at) last FROM messages WHERE direction='in' GROUP BY tenant_id") as $r) $last[$r['tenant_id']] = $r['last'];
        $alerts = []; foreach (Db::all("SELECT tenant_id, COUNT(*) n, SUM(severity='crit') crit FROM alerts WHERE resolved_at IS NULL AND tenant_id IS NOT NULL GROUP BY tenant_id") as $r) $alerts[$r['tenant_id']] = $r;
        foreach ($rows as &$r) {
            $id = $r['id'];
            $r['cost'] = self::cost((int)$r['tin'], (int)$r['tout']);
            $r['calls24'] = (int)($err[$id]['n'] ?? 0); $r['bad24'] = (int)($err[$id]['bad'] ?? 0); $r['avg_ms'] = (int)($err[$id]['avg_ms'] ?? 0);
            $r['pending'] = (int)($pend[$id]['pend'] ?? 0); $r['failed'] = (int)($pend[$id]['failed'] ?? 0);
            $r['messages'] = $rowsN[$id] ?? 0; $r['last_in'] = $last[$id] ?? null;
            $r['alerts'] = (int)($alerts[$id]['n'] ?? 0); $r['alerts_crit'] = (int)($alerts[$id]['crit'] ?? 0);
        }
        unset($r);
        $p['tenants'] = $rows;
        $p['month_cost'] = array_sum(array_column($rows, 'cost'));
        $p['month_tokens'] = array_sum(array_column($rows, 'tin')) + array_sum(array_column($rows, 'tout'));
        $p['alerts'] = Db::all("SELECT a.*, t.name tname FROM alerts a LEFT JOIN tenants t ON t.id=a.tenant_id WHERE a.resolved_at IS NULL ORDER BY a.severity='crit' DESC, a.id DESC LIMIT 25");

        // 14-day platform series
        $ser = [];
        for ($i = 13; $i >= 0; $i--) $ser[gmdate('Y-m-d', time() - $i * 86400)] = ['replies' => 0, 'tokens' => 0, 'errors' => 0];
        foreach (Db::all("SELECT day, SUM(ai_replies) r, SUM(tokens_in+tokens_out) tk, SUM(ai_errors) e FROM usage_daily WHERE day >= ? GROUP BY day", [gmdate('Y-m-d', time() - 13 * 86400)]) as $r) if (isset($ser[$r['day']])) $ser[$r['day']] = ['replies' => (int)$r['r'], 'tokens' => (int)$r['tk'], 'errors' => (int)$r['e']];
        $p['series'] = $ser;
        $p['posture'] = self::posture();
        return $p;
    }

    /** Security / configuration checks shown to the platform admin. @return array<int,array{ok:bool,label:string,hint:string}> */
    public static function posture(): array {
        $dbUser = (string)env('DB_USER', 'root');
        return [
            ['ok' => IS_PROD, 'label' => 'Running in production mode (APP_ENV=production)', 'hint' => 'Hides debug output and forces private-host connectors off.'],
            ['ok' => !APP_DEBUG, 'label' => 'Debug output disabled', 'hint' => 'APP_DEBUG must be 0 on any public server.'],
            ['ok' => !ALLOW_PRIVATE_HOSTS, 'label' => 'Connectors blocked from private/internal addresses', 'hint' => 'Set ALLOW_PRIVATE_HOSTS=0 (automatic in production).'],
            ['ok' => $dbUser !== 'root', 'label' => 'Database user is not root', 'hint' => 'Create a least-privilege user limited to this database.'],
            ['ok' => (string)env('DB_PASS', '') !== '', 'label' => 'Database user has a password', 'hint' => 'Empty database passwords are for local development only.'],
            ['ok' => is_https(), 'label' => 'This request is served over HTTPS', 'hint' => 'Required for Meta webhooks and secure cookies.'],
            ['ok' => (string)env('GEMINI_API_KEY', '') !== '', 'label' => 'Gemini API key configured', 'hint' => 'Platform key in .env (tenants may bring their own).'],
            ['ok' => is_file(dirname(ROOT) . DIRECTORY_SEPARATOR . 'calichat.env'), 'label' => 'Secrets file kept outside the web root', 'hint' => 'Optional but recommended: move .env to ../calichat.env (one folder above the app).'],
            ['ok' => count(array_filter(explode(',', (string)env('APP_KEYS', '')))) >= 1, 'label' => 'Versioned encryption keys (APP_KEYS) in use', 'hint' => 'Allows key rotation with cli/rotate_keys.php.'],
            ['ok' => Queue::workerAlive(), 'label' => 'Queue worker daemon running', 'hint' => 'Start start-worker.bat (or systemd). Without it the webhook falls back to inline processing.'],
        ];
    }
}
