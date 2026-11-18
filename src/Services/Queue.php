<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;

/**
 * Durable inbound queue. The webhook only stores events; workers process them with:
 *  - at-least-once delivery (events survive crashes; stale locks are recovered)
 *  - per-sender ordering (an event waits while an earlier one from the same sender is unfinished)
 *  - idempotency (unique channel_id + provider message id)
 *  - retries with back-off and a dead-letter state ('failed') visible in the dashboard
 */
class Queue {
    public const MAX_ATTEMPTS = 5;      // technical failures
    public const AI_FINAL_ATTEMPT = 3;  // after this many tries a transient AI outage falls back to the holding reply + handoff

    public static function enqueue(array $channel, array $ev): bool {
        $r = Db::q("INSERT IGNORE INTO inbound_events (tenant_id, channel_id, provider_id, sender, sender_name, phone, msg_type, text, media_ref, mime, referral, reply_id, next_attempt_at, received_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
            [$channel['tenant_id'], $channel['id'], mb_substr((string)$ev['id'], 0, 190), mb_substr((string)$ev['sender'], 0, 100), $ev['name'] ? mb_substr((string)$ev['name'], 0, 150) : null,
             $ev['phone'] ? mb_substr((string)$ev['phone'], 0, 40) : null, $ev['type'], mb_substr((string)$ev['text'], 0, 4000), $ev['media_ref'] ? mb_substr((string)$ev['media_ref'], 0, 600) : null, $ev['mime'], !empty($ev['referral']) ? mb_substr(json_encode($ev['referral'], JSON_UNESCAPED_UNICODE), 0, 2000) : null, !empty($ev['reply_id']) ? mb_substr((string)$ev['reply_id'], 0, 100) : null]);
        return $r->rowCount() > 0;
    }

    public static function claim(string $worker): ?array {
        $tok = $worker . ':' . bin2hex(random_bytes(4));
        $n = Db::q("UPDATE inbound_events SET status='processing', locked_by=?, locked_at=NOW(), attempts=attempts+1
            WHERE id = (SELECT id FROM (
                SELECT x.id FROM inbound_events x
                WHERE x.status IN ('received','retry') AND x.next_attempt_at <= NOW()
                  AND NOT EXISTS (SELECT 1 FROM inbound_events y WHERE y.channel_id=x.channel_id AND y.sender=x.sender AND y.id < x.id AND y.status IN ('received','retry','processing'))
                ORDER BY x.id LIMIT 1) t)
              AND status IN ('received','retry')", [$tok])->rowCount();
        return $n ? Db::one("SELECT * FROM inbound_events WHERE locked_by=? AND status='processing'", [$tok]) : null;
    }

    public static function recoverStale(): int {
        return Db::q("UPDATE inbound_events SET status='retry', next_attempt_at=NOW(), locked_by=NULL, last_error='worker stopped mid-processing' WHERE status='processing' AND locked_at < NOW() - INTERVAL 180 SECOND")->rowCount();
    }

    public static function beat(string $name, string $info = ''): void {
        Db::q("INSERT INTO worker_heartbeats (name, last_beat, info) VALUES (?,NOW(),?) ON DUPLICATE KEY UPDATE last_beat=NOW(), info=VALUES(info)", [$name, mb_substr($info, 0, 190)]);
    }
    public static function workerAlive(): bool {
        return (int)Db::val("SELECT COUNT(*) FROM worker_heartbeats WHERE name='queue' AND last_beat > NOW() - INTERVAL 30 SECOND") > 0;
    }

    /** Handle one claimed event, converting any failure into a retry or a dead-letter. */
    public static function runOne(array $ev): void {
        $t0 = microtime(true);
        Log::with(['tenant' => (int)$ev['tenant_id'], 'event' => (int)$ev['id']]);
        try {
            $outcome = self::process($ev);
            if ($outcome === 'retry') return; // process() already rescheduled it
            Db::update('inbound_events', ['status' => $outcome === 'skipped' ? 'skipped' : 'done', 'outcome' => mb_substr($outcome, 0, 40), 'processed_at' => now(), 'locked_by' => null, 'last_error' => null], 'id=?', [$ev['id']]);
            Log::info('event_done', ['outcome' => $outcome, 'ms' => (int)((microtime(true) - $t0) * 1000)]);
        } catch (\Throwable $e) {
            Log::exception($e, 'event_failed', ['attempt' => (int)$ev['attempts']]);
            if ((int)$ev['attempts'] >= self::MAX_ATTEMPTS) {
                Db::update('inbound_events', ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 490), 'processed_at' => now(), 'locked_by' => null], 'id=?', [$ev['id']]);
                Notifier::alert((int)$ev['tenant_id'], 'crit', 'queue_failed', 'A customer message could not be processed after several attempts and needs attention (Monitoring > Failed messages).', 'qfail:' . $ev['id'], 24);
            } else {
                self::reschedule((int)$ev['id'], 30 * (int)$ev['attempts'], $e->getMessage());
            }
        }
        Log::$ctx = [];
    }

    private static function reschedule(int $id, int $seconds, string $err): void {
        Db::q("UPDATE inbound_events SET status='retry', next_attempt_at = NOW() + INTERVAL ? SECOND, locked_by=NULL, last_error=? WHERE id=?", [$seconds, mb_substr($err, 0, 490), $id]);
    }

    /** @return string outcome: replied|handoff|keyword|skipped|human|retry... */
    private static function process(array $ev): string {
        $tid = (int)$ev['tenant_id'];
        $tenant = Tenant::get($tid);
        $channel = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$ev['channel_id'], $tid]);
        if (!$tenant || $tenant['status'] !== 'active' || !$channel || $channel['status'] !== 'active') return 'skipped';
        set_tz($tenant['timezone']);

        $contact = Chat::contact($tid, (int)$channel['id'], $channel['type'], $ev['sender'], $ev['sender_name'], $ev['phone']);
        if (!empty($ev['referral']) && empty($contact['acquisition_source'])) $contact = Chat::applyReferral($contact, (string)$ev['referral']);
        $lock = 'conv:' . $tid . ':' . $contact['id'];
        if (!Db::lock($lock, 30)) { self::reschedule((int)$ev['id'], 10, 'conversation busy'); return 'retry'; }
        try {
            $final = (int)$ev['attempts'] >= self::AI_FINAL_ATTEMPT;
            $agent = Chat::agentFor($tid, $channel['agent_id'] ? (int)$channel['agent_id'] : null);
            $conv = Chat::conversation($tid, $channel, $channel['type'], $contact, $agent['id'] ?? null);
            $text = (string)$ev['text'];
            $rid = (string)($ev['reply_id'] ?? '');
            if ($rid !== '' && !$ev['message_id']) $text = Catalogue::tapText($tid, $rid, $text); // "Add to order" on a product card becomes "Add to order: Cotton T-Shirt (TS-001)"

            if (!$ev['message_id']) {
                if ($ev['media_ref'] && in_array($ev['msg_type'], ['audio', 'image'], true)) {
                    try {
                        $m = Media::toText($tenant, $channel, $ev['msg_type'], $ev['media_ref'], $ev['mime'], $text ?: null, (int)$conv['id']); $text = $m['text'];
                        if (!empty($m['bytes'])) { $slip = Slips::maybe($tenant, $contact, (int)$conv['id'], $m['bytes'], (string)$m['mime']); if ($slip !== null) $text = $slip . ($ev['text'] ? "\n" . $ev['text'] : ''); } // a payment slip becomes a clear instruction for the AI
                    }
                    catch (LlmException $e) {
                        if (!$final) { self::reschedule((int)$ev['id'], 20, $e->getMessage()); return 'retry'; }
                        $text = ($ev['msg_type'] === 'audio' ? '[Voice message] ' : '[Image] ') . '(could not be read, ask the customer to type it)';
                    }
                }
                $mid = Chat::addMessage($tid, (int)$conv['id'], 'in', 'customer', $text, [], $ev['msg_type'] !== 'text' ? $ev['msg_type'] : null, $ev['provider_id']);
                Db::update('inbound_events', ['message_id' => $mid, 'conversation_id' => $conv['id'], 'text' => mb_substr($text, 0, 4000)], 'id=?', [$ev['id']]);
            }

            $deliver = function (string $t, array $rich = []) use ($channel, $ev, $tenant, $conv) {
                if (!$rich && $ev['msg_type'] === 'audio' && Voice::enabled($tenant, $channel)) { $v = Voice::send($tenant, $channel, $ev['sender'], $t, (int)$conv['id']); if ($v['ok']) return $v + ['voice' => true]; } // the customer spoke, so answer by voice; fall back to text
                $r = $rich ? Interactive::deliver($channel, $ev['sender'], $t, $rich) : Meta::send($channel, $ev['sender'], $t);
                if (!$r['ok'] && preg_match('/HTTP (5\d\d|0)\b/', (string)($r['error'] ?? ''))) { sleep(2); $r = Meta::send($channel, $ev['sender'], $t); }
                return $r;
            };

            if ($rid === '' && ($kw = Chat::consentKeyword($tid, $contact, $text))) { // a button tap such as "Cancel order" is never an unsubscribe keyword
                $d = $deliver($kw);
                Chat::addMessage($tid, (int)$conv['id'], 'out', 'ai', $kw, ['keyword' => true] + ($d['ok'] ? [] : ['delivery_error' => $d['error'] ?? 'send failed']));
                return 'keyword';
            }

            if (in_array($rid, ['order_confirm', 'order_cancel'], true)) { // Confirm / Cancel on the order summary: handled by the server, not the model
                $t = OrderFlow::handleTap($tenant, $channel, $contact, $conv, $rid, $deliver);
                if ($t) return $t['handoff'] ? 'handoff' : 'replied';
            }
            $r = Chat::respond($tenant, $channel, $contact, $conv, $text, $deliver, ['final' => $final]);
            if ($r['retry']) { self::reschedule((int)$ev['id'], $r['retry_after'] * (int)$ev['attempts'], (string)$r['error']); return 'retry'; }
            if ($r['skipped']) return $r['skipped'] === 'human_takeover' ? 'human' : 'skipped:' . $r['skipped'];
            return $r['handoff'] ? 'handoff' : 'replied';
        } finally { Db::unlock($lock); }
    }

    /** Process up to N events inline (used by the webhook only when no worker daemon is running). */
    public static function drain(int $maxEvents = 5, int $maxSeconds = 40): int {
        $n = 0; $t0 = time();
        while ($n < $maxEvents && time() - $t0 < $maxSeconds) {
            $ev = self::claim('inline' . getmypid());
            if (!$ev) break;
            self::runOne($ev); $n++;
        }
        return $n;
    }

    public static function requeue(int $id, int $tenantId): bool {
        return Db::q("UPDATE inbound_events SET status='retry', attempts=0, next_attempt_at=NOW(), locked_by=NULL, last_error=NULL WHERE id=? AND tenant_id=? AND status IN ('failed','skipped')", [$id, $tenantId])->rowCount() > 0;
    }
}
