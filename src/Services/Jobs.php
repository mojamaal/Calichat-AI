<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;

/** Background jobs: campaign sending, retention/cleanup, auto-resume of AI, platform health checks. */
class Jobs {
    public static function campaignTick(): int {
        if (!Db::lock('campaign_tick', 0)) return 0; // another worker is already sending
        $sent = 0;
        try {
            Db::q("UPDATE campaign_recipients SET status='failed', error='worker stopped before sending' WHERE status='sending' AND claimed_at < NOW() - INTERVAL 15 MINUTE");
            $wid = 'w' . getmypid() . ':' . bin2hex(random_bytes(3));
            foreach (Db::all("SELECT * FROM campaigns WHERE status='running'") as $camp) {
                $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$camp['channel_id'], $camp['tenant_id']]);
                $batch = (int)env('CAMPAIGN_BATCH', 30);
                Db::q("UPDATE campaign_recipients SET status='sending', worker=?, claimed_at=NOW() WHERE campaign_id=? AND status='queued' LIMIT $batch", [$wid, $camp['id']]);
                $rows = Db::all("SELECT r.id, ct.external_id, ct.name, ct.phone, ct.consent_status FROM campaign_recipients r JOIN contacts ct ON ct.id=r.contact_id WHERE r.campaign_id=? AND r.status='sending' AND r.worker=?", [$camp['id'], $wid]);
                foreach ($rows as $r) {
                    $text = str_replace('{name}', Agent::safeName($r['name']) ?: 'there', $camp['body']);
                    if ($r['consent_status'] !== 'opted_in') $res = ['ok' => false, 'error' => 'Contact is no longer opted in'];
                    elseif (!$ch || $ch['status'] !== 'active') $res = ['ok' => false, 'error' => 'Channel unavailable'];
                    elseif ($ch['type'] === 'whatsapp' && $camp['template_name']) {
                        $tp = Templates::approved((int)$camp['tenant_id'], (string)$camp['template_name'], (string)$camp['template_lang']); // managed template: fill every {placeholder}
                        $res = $tp ? Templates::send($ch, $r['phone'] ?: $r['external_id'], $tp, ['name' => Agent::safeName($r['name']) ?: 'there', 'shop' => (string)Tenant::get((int)$camp['tenant_id'])['name']])
                                   : Meta::sendTemplate($ch, $r['phone'] ?: $r['external_id'], $camp['template_name'], $camp['template_lang'], [$text]);
                    }
                    else $res = Meta::send($ch, $r['external_id'], $text);
                    Db::update('campaign_recipients', ['status' => $res['ok'] ? 'sent' : 'failed', 'error' => $res['ok'] ? null : mb_substr((string)$res['error'], 0, 290), 'sent_at' => now()], 'id=?', [$r['id']]);
                    $sent++; usleep(120000);
                }
                if (!(int)Db::val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=? AND status IN ('queued','sending')", [$camp['id']])) Db::update('campaigns', ['status' => 'done'], 'id=?', [$camp['id']]);
            }
        } finally { Db::unlock('campaign_tick'); }
        return $sent;
    }

    /** Trials, renewal invoices, grace and lapse (hourly is plenty). */
    public static function billingTick(): array { return Billing::tick(); }

    /** Follow-up automations: send what is due (every 30 s). */
    public static function automationTick(): int { $r = Automations::tick(); return $r['sent'] + $r['failed'] + $r['skipped']; }
    /** Follow-up automations: schedule time-based triggers, and pull WhatsApp template approvals (every few minutes). */
    public static function automationScan(): array { return ['scheduled' => Automations::scan(), 'templates_updated' => Templates::syncAll(), 'catalogue_synced' => Importers::syncAll()]; }

    /** Return conversations to the AI after a tenant-configured quiet period (opt-in per tenant). */
    public static function autoResume(): int {
        $n = 0;
        foreach (Db::all("SELECT id, settings FROM tenants WHERE status='active'") as $t) {
            $cfg = json_decode((string)$t['settings'], true) ?: [];
            $min = (int)($cfg['auto_resume_minutes'] ?? 0);
            if ($min <= 0) continue;
            $ids = Db::all("SELECT id FROM conversations WHERE tenant_id=? AND status='human' AND is_test=0 AND last_message_at < NOW() - INTERVAL ? MINUTE", [$t['id'], $min]);
            foreach ($ids as $c) {
                Db::update('conversations', ['status' => 'ai', 'handoff_reason' => null], 'id=?', [$c['id']]);
                Db::q("UPDATE handoffs SET resolved_at=NOW(), resolved_by='auto' WHERE conversation_id=? AND resolved_at IS NULL", [$c['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * A person handed a chat back to the AI while the customer's last message was still unanswered: answer it now.
     * (conversations.ai_catchup is set by the inbox; an unanswered message means the newest message in the chat is from the customer.)
     */
    public static function catchUp(): int {
        $n = 0;
        foreach (Db::all("SELECT id, tenant_id, contact_id, channel_id FROM conversations WHERE status='ai' AND ai_catchup=1 AND is_test=0 ORDER BY id LIMIT 5") as $c) {
            if (!Db::q("UPDATE conversations SET ai_catchup=0 WHERE id=? AND ai_catchup=1", [$c['id']])->rowCount()) continue; // another worker took it
            $tid = (int)$c['tenant_id']; $lock = 'conv:' . $tid . ':' . $c['contact_id'];
            if (!Db::lock($lock, 5)) { Db::update('conversations', ['ai_catchup' => 1], 'id=?', [$c['id']]); continue; }
            try {
                $last = Db::one("SELECT sender, content FROM messages WHERE conversation_id=? AND tenant_id=? AND sender IN ('customer','ai','human') ORDER BY id DESC LIMIT 1", [$c['id'], $tid]);
                $tenant = Tenant::get($tid); $channel = $c['channel_id'] ? Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$c['channel_id'], $tid]) : null;
                $contact = Db::one("SELECT * FROM contacts WHERE id=? AND tenant_id=?", [$c['contact_id'], $tid]); $conv = Db::one("SELECT * FROM conversations WHERE id=?", [$c['id']]);
                if (!$last || $last['sender'] !== 'customer' || !$tenant || $tenant['status'] !== 'active' || !$channel || $channel['status'] !== 'active' || !$contact || !$conv) continue;
                set_tz($tenant['timezone']);
                $deliver = fn(string $t, array $rich = []) => $rich ? Interactive::deliver($channel, (string)$contact['external_id'], $t, $rich) : Meta::send($channel, (string)$contact['external_id'], $t);
                Chat::respond($tenant, $channel, $contact, $conv, (string)$last['content'], $deliver, ['final' => true]); $n++;
            } catch (\Throwable $e) { Log::exception($e, 'catchup_failed', ['conv' => (int)$c['id']]); }
            finally { Db::unlock($lock); }
        }
        return $n;
    }

    public static function maintenance(): array {
        $out = [];
        $out['tool_cache'] = Db::q("DELETE FROM tool_cache WHERE expires_at < NOW()")->rowCount();
        $out['rate_limits'] = Db::q("DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 7200")->rowCount();
        $out['login_attempts'] = Db::q("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY")->rowCount();
        $out['events'] = Db::q("DELETE FROM inbound_events WHERE status IN ('done','skipped') AND processed_at < NOW() - INTERVAL 14 DAY")->rowCount();
        $out['ai_calls'] = Db::q("DELETE FROM ai_calls WHERE created_at < NOW() - INTERVAL 90 DAY")->rowCount();
        $out['tool_logs'] = Db::q("DELETE FROM tool_logs WHERE created_at < NOW() - INTERVAL 60 DAY")->rowCount();
        $out['alerts'] = Db::q("DELETE FROM alerts WHERE resolved_at IS NOT NULL AND resolved_at < NOW() - INTERVAL 90 DAY")->rowCount();
        $out['audit'] = Db::q("DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 400 DAY")->rowCount();
        $out['resets'] = Db::q("DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY")->rowCount();
        // Per-tenant data retention (tenant setting retention_days; 0 = keep)
        $out['retention_msgs'] = 0;
        foreach (Db::all("SELECT id, settings FROM tenants") as $t) {
            $days = (int)((json_decode((string)$t['settings'], true) ?: [])['retention_days'] ?? 0);
            if ($days < 30) continue;
            $out['retention_msgs'] += Db::q("DELETE FROM messages WHERE tenant_id=? AND created_at < NOW() - INTERVAL ? DAY", [$t['id'], $days])->rowCount();
            Db::q("DELETE FROM conversations WHERE tenant_id=? AND last_message_at < NOW() - INTERVAL ? DAY AND status='closed'", [$t['id'], $days]);
            Db::q("DELETE FROM tool_logs WHERE tenant_id=? AND created_at < NOW() - INTERVAL ? DAY", [$t['id'], $days]);
        }
        foreach (glob(ROOT . '/storage/logs/app-*.log') ?: [] as $f) if (filemtime($f) < time() - 14 * 86400) @unlink($f);
        return $out;
    }

    /** Platform-level alerts: queue lag, dead worker, failed events. */
    public static function healthChecks(): void {
        $pending = (int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE status IN ('received','retry') AND next_attempt_at <= NOW()");
        $oldest = (int)Db::val("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(received_at), NOW()),0) FROM inbound_events WHERE status IN ('received','retry') AND next_attempt_at <= NOW()");
        if ($pending > 0 && $oldest > 120 && !Queue::workerAlive() && env('QUEUE_INLINE_DRAIN', '1') !== '1') {
            Notifier::alert(null, 'crit', 'worker_down', "$pending customer messages are waiting and no queue worker is running (oldest {$oldest}s). Start cli/worker.php.", 'worker_down', 1);
        } elseif ($oldest > 300) {
            Notifier::alert(null, 'crit', 'queue_lag', "Message queue is lagging: oldest pending message is {$oldest}s old ($pending waiting).", 'queue_lag', 1);
        }
        // Tenants nearing limits
        foreach (Db::all("SELECT id, name, monthly_message_limit, monthly_token_limit FROM tenants WHERE status='active'") as $t) {
            $m = Tenant::monthMessages((int)$t['id']);
            if ($t['monthly_message_limit'] > 0 && $m >= 0.8 * $t['monthly_message_limit']) Notifier::alert((int)$t['id'], 'warn', 'quota_80', "You have used " . round(100 * $m / $t['monthly_message_limit']) . "% of this month's AI replies.", 'quota80:' . gmdate('Ym'), 24 * 20);
            $tk = Tenant::monthTokens((int)$t['id']);
            if ($t['monthly_token_limit'] > 0 && $tk >= 0.8 * $t['monthly_token_limit']) Notifier::alert((int)$t['id'], 'warn', 'tokens_80', "You have used " . round(100 * $tk / $t['monthly_token_limit']) . "% of this month's AI token budget.", 'tok80:' . gmdate('Ym'), 24 * 20);
        }
        // High AI error rate in the last 15 minutes
        foreach (Db::all("SELECT tenant_id, COUNT(*) n, SUM(ok=0) bad FROM ai_calls WHERE created_at > NOW() - INTERVAL 15 MINUTE AND is_test=0 GROUP BY tenant_id HAVING n >= 10 AND bad / n > 0.3") as $r) {
            Notifier::alert((int)$r['tenant_id'], 'warn', 'ai_errors', "{$r['bad']} of {$r['n']} AI calls failed in the last 15 minutes.", 'ai_err', 1);
        }
    }
}
