<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;

/**
 * Follow-up automations. An event (order shipped) or a periodic scan (chat went quiet) schedules a run; the worker sends it
 * when due, after re-checking that it still makes sense. Every run ends as sent, skipped (with the reason) or failed, so the
 * team can see exactly why a customer did or did not get a message.
 */
class Automations {
    /** trigger => [label, kind, what it needs] */
    public const TRIGGERS = [
        'order_placed'    => ['Order placed', 'order', 'Sent right after an order is created.'],
        'order_packed'    => ['Order packed', 'order', 'Sent when you mark an order Packed.'],
        'order_shipped'   => ['Order shipped', 'order', 'Sent when you mark an order Shipped. Include {courier} and {tracking}.'],
        'order_delivered' => ['Order delivered', 'order', 'Sent when you mark an order Delivered.'],
        'order_cancelled' => ['Order cancelled', 'order', 'Sent when an order is cancelled.'],
        'payment_pending' => ['Payment still pending', 'order', 'Orders paid by bank transfer or card that are still unpaid after the delay.'],
        'review_request'  => ['Ask for a review', 'order', 'Sent after delivery. Marketing: needs the customer\'s opt-in.'],
        'chat_idle'       => ['Chat went quiet', 'chat', 'Customer looked at products, we replied, then silence for the delay. Marketing: needs opt-in.'],
        'lead_stale'      => ['Lead not followed up', 'lead', 'Alerts your team about a New lead nobody has moved forward.'],
        'lead_created'    => ['New lead from an ad form', 'lead', 'Welcome message to someone who just filled a Facebook/Instagram lead form. They asked to be contacted, so this counts as consent. Needs an approved template (they have not chatted with you yet).'],
        'appointment_booked'   => ['Appointment booked', 'appt', 'Sent right after an appointment is booked (confirmation). Placeholders: {service} {date} {time}.'],
        'appointment_reminder' => ['Appointment reminder', 'appt', 'Sent this many minutes BEFORE the appointment starts (the Wait setting), for example 180 = 3 hours before.'],
    ];
    private const EVENT_TRIGGERS = ['appointment_booked' => ['appointment_booked'], 'lead_created' => ['lead_created'], 'order_placed' => ['order_placed'], 'order_packed' => ['order_packed'], 'order_shipped' => ['order_shipped'], 'order_delivered' => ['order_delivered', 'review_request'], 'order_cancelled' => ['order_cancelled']];
    private const MARKETING = ['chat_idle', 'review_request'];
    private const WINDOW_SECONDS = 86400;

    // ------------------------------------------------------------------ scheduling
    /** An event happened (never throws: a store action must not fail because of an automation). */
    public static function emit(int $tid, string $event, string $subjectType, int $subjectId): void {
        try {
            $trigs = self::EVENT_TRIGGERS[$event] ?? []; if (!$trigs) return;
            $phone = $subjectType === 'order' ? (string)Db::val("SELECT phone FROM store_orders WHERE id=? AND tenant_id=?", [$subjectId, $tid]) : null;
            $in = implode(',', array_fill(0, count($trigs), '?'));
            foreach (Db::all("SELECT id, delay_minutes FROM automations WHERE tenant_id=? AND is_active=1 AND trigger_type IN ($in)", array_merge([$tid], $trigs)) as $a) {
                Db::q("INSERT IGNORE INTO automation_runs (tenant_id, automation_id, subject_type, subject_id, phone, due_at) VALUES (?,?,?,?,?, NOW() + INTERVAL ? MINUTE)", [$tid, $a['id'], $subjectType, $subjectId, $phone ?: null, (int)$a['delay_minutes']]);
            }
        } catch (\Throwable $e) { Log::exception($e, 'automation_emit_failed'); }
    }

    /** Periodic scan for triggers that are about the absence of something (silence, unpaid, untouched lead). @return int runs scheduled */
    public static function scan(): int {
        $n = 0;
        foreach (Db::all("SELECT a.* FROM automations a JOIN tenants t ON t.id=a.tenant_id WHERE a.is_active=1 AND t.status='active' AND a.trigger_type IN ('payment_pending','chat_idle','lead_stale','appointment_reminder')") as $a) {
            $tid = (int)$a['tenant_id']; $d = max(1, (int)$a['delay_minutes']);
            if ($a['trigger_type'] === 'payment_pending') {
                $rows = Db::all("SELECT id, phone FROM store_orders WHERE tenant_id=? AND payment_status='unpaid' AND payment_method<>'Cash on delivery' AND status IN ('processing','packed') AND created_at <= NOW() - INTERVAL ? MINUTE AND created_at > NOW() - INTERVAL 14 DAY LIMIT 200", [$tid, $d]);
                $type = 'order';
            } elseif ($a['trigger_type'] === 'appointment_reminder') {
                $rows = Db::all("SELECT id, phone FROM appointments WHERE tenant_id=? AND status='booked' AND start_at > NOW() AND start_at <= NOW() + INTERVAL ? MINUTE LIMIT 200", [$tid, $d]);
                $type = 'appt';
            } elseif ($a['trigger_type'] === 'lead_stale') {
                $rows = Db::all("SELECT id, phone FROM crm_leads WHERE tenant_id=? AND stage='new' AND created_at <= NOW() - INTERVAL ? MINUTE AND created_at > NOW() - INTERVAL 30 DAY LIMIT 200", [$tid, $d]);
                $type = 'lead';
            } else {
                $rows = Db::all("SELECT (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id=c.id AND m.sender='customer') id, COALESCE(k.phone, k.external_id) phone
                    FROM conversations c JOIN contacts k ON k.id=c.contact_id
                    WHERE c.tenant_id=? AND c.is_test=0 AND c.status='ai' AND c.last_customer_at IS NOT NULL AND c.last_message_at <= NOW() - INTERVAL ? MINUTE AND c.last_message_at > NOW() - INTERVAL 7 DAY
                      AND (SELECT m2.direction FROM messages m2 WHERE m2.conversation_id=c.id AND m2.sender<>'note' ORDER BY m2.id DESC LIMIT 1)='out'
                      AND EXISTS (SELECT 1 FROM tool_logs t WHERE t.conversation_id=c.id AND t.tool IN ('search_products','get_product'))
                    LIMIT 200", [$tid, $d]);
                $type = 'chat';
            }
            foreach ($rows as $r) {
                if (!$r['id']) continue;
                $n += Db::q("INSERT IGNORE INTO automation_runs (tenant_id, automation_id, subject_type, subject_id, phone, due_at) VALUES (?,?,?,?,?,NOW())", [$tid, $a['id'], $type, $r['id'], $r['phone'] ? Store::digits((string)$r['phone']) : null])->rowCount();
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ sending
    /** Send everything that is due. @return array{sent:int,skipped:int,failed:int,later:int} */
    public static function tick(?int $nowTs = null, int $limit = 100): array {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'later' => 0];
        if (!Db::lock('automation_tick', 0)) return $out;
        try {
            foreach (Db::all("SELECT * FROM automation_runs WHERE status='scheduled' AND due_at <= NOW() ORDER BY due_at LIMIT " . (int)$limit) as $run) {
                try { [$st, $why] = self::execute($run, $nowTs ?? time()); }
                catch (\Throwable $e) { Log::exception($e, 'automation_run_failed'); [$st, $why] = ['failed', mb_substr($e->getMessage(), 0, 250)]; }
                if ($st === 'later') { $out['later']++; continue; }
                Db::update('automation_runs', ['status' => $st, 'reason' => $why ? mb_substr($why, 0, 290) : null, 'done_at' => now()], 'id=?', [$run['id']]);
                $out[$st]++;
            }
        } finally { Db::unlock('automation_tick'); }
        return $out;
    }

    /** Is this timestamp inside the tenant's quiet hours (default 21:00 to 08:00 local time)? */
    public static function isQuiet(int $tid, int $ts): bool {
        $cfg = Tenant::get($tid)['cfg'] ?? [];
        $s = (string)($cfg['quiet_start'] ?? '21:00'); $e = (string)($cfg['quiet_end'] ?? '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e) || $s === $e) return false;
        $d = new \DateTime('@' . $ts); $d->setTimezone(new \DateTimeZone(Tenant::tz($tid))); $h = $d->format('H:i');
        return $s < $e ? ($h >= $s && $h < $e) : ($h >= $s || $h < $e);
    }

    /** @return array{0:string,1:?string} [sent|skipped|failed|later, reason] */
    private static function execute(array $run, int $nowTs): array {
        $tid = (int)$run['tenant_id'];
        $a = Db::one("SELECT * FROM automations WHERE id=? AND tenant_id=?", [$run['automation_id'], $tid]);
        $tenant = Tenant::get($tid);
        if (!$a || !$tenant || $tenant['status'] !== 'active') return ['skipped', 'Automation or workspace is not active'];
        if (!(int)$a['is_active']) return ['skipped', 'Automation was switched off'];
        $trig = $a['trigger_type'];

        if ($trig === 'lead_stale') {
            $l = Db::one("SELECT * FROM crm_leads WHERE id=? AND tenant_id=?", [$run['subject_id'], $tid]);
            if (!$l || $l['stage'] !== 'new') return ['skipped', 'Lead has already been worked on'];
            Notifier::alert($tid, 'warn', 'lead_stale', "Lead #{$l['id']} ({$l['name']}: {$l['interest']}) has been waiting since " . local_dt($l['created_at']) . '. Call or message them.', 'lead' . $l['id'], 24);
            return ['sent', 'Team alerted'];
        }
        if (self::isQuiet($tid, $nowTs)) return ['later', null];

        // ---- gather context and re-check that the message still makes sense
        $cond = json_decode((string)$a['conditions'], true) ?: [];
        $ctx = ['shop' => (string)$tenant['name']]; $contact = null; $phone = (string)$run['phone']; $channel = null;
        if ($run['subject_type'] === 'order') {
            $o = Db::one("SELECT * FROM store_orders WHERE id=? AND tenant_id=?", [$run['subject_id'], $tid]);
            if (!$o) return ['skipped', 'Order no longer exists'];
            $need = ['order_placed' => null, 'order_packed' => ['packed'], 'order_shipped' => ['shipped'], 'order_delivered' => ['delivered'], 'review_request' => ['delivered'], 'order_cancelled' => ['cancelled'], 'payment_pending' => ['processing', 'packed']][$trig] ?? null;
            if ($need !== null && !in_array($o['status'], $need, true)) return ['skipped', 'Order is now ' . ucfirst($o['status'])];
            if ($trig === 'order_placed' && $o['status'] === 'cancelled') return ['skipped', 'Order was cancelled'];
            if ($trig === 'payment_pending' && $o['payment_status'] !== 'unpaid') return ['skipped', 'Order is already paid'];
            if (!empty($cond['source']) && $cond['source'] !== $o['source']) return ['skipped', 'Order was placed by ' . ($o['source'] === 'ai' ? 'the AI (customer already confirmed in chat)' : ($o['source'] === 'catalogue' ? 'the customer on the catalogue page' : 'staff'))];
            if (!empty($cond['min_total']) && (float)$o['total'] < (float)$cond['min_total']) return ['skipped', 'Order total is below the minimum'];
            if (!empty($cond['payment_method']) && $cond['payment_method'] !== $o['payment_method']) return ['skipped', 'Different payment method'];
            $phone = (string)$o['phone'];
            $items = array_map(fn($i) => $i['qty'] . ' x ' . $i['name'], Db::all("SELECT name, qty FROM store_order_items WHERE order_id=? ORDER BY id", [$o['id']]));
            $ctx += ['order_no' => $o['order_no'], 'total' => 'Rs. ' . number_format((float)$o['total']), 'status' => ucfirst($o['status']), 'tracking' => (string)$o['tracking'] ?: 'to be shared', 'courier' => (string)$o['courier'] ?: 'our courier',
                'eta' => (string)$o['eta'] ?: 'soon', 'items' => implode(', ', $items), 'city' => (string)$o['delivery_city'], 'payment_method' => (string)$o['payment_method'], 'name' => (string)$o['customer_name']];
        } elseif ($run['subject_type'] === 'lead') {
            $ld = Db::one("SELECT * FROM crm_leads WHERE id=? AND tenant_id=?", [$run['subject_id'], $tid]);
            if (!$ld || in_array($ld['stage'], ['won', 'lost'], true)) return ['skipped', 'The lead is already closed'];
            if (strlen((string)$ld['phone']) < 9) return ['skipped', 'The lead form had no phone number'];
            $phone = (string)$ld['phone']; $ctx += ['name' => (string)$ld['name'], 'interest' => (string)$ld['interest']];
        } elseif ($run['subject_type'] === 'appt') {
            $ap = Db::one("SELECT * FROM appointments WHERE id=? AND tenant_id=?", [$run['subject_id'], $tid]);
            if (!$ap || $ap['status'] !== 'booked') return ['skipped', 'The appointment is no longer booked'];
            if ($ap['start_at'] <= gmdate('Y-m-d H:i:s', $nowTs)) return ['skipped', 'The appointment time has passed'];
            $v = Appointments::view($tid, $ap); $phone = (string)$ap['phone'];
            $ctx += ['name' => (string)$ap['customer_name'], 'service' => $v['service'], 'date' => $v['day'], 'time' => $v['time']];
        } else { // chat
            $c = Db::one("SELECT * FROM conversations WHERE id=(SELECT conversation_id FROM messages WHERE id=? LIMIT 1) AND tenant_id=?", [$run['subject_id'], $tid]);
            if (!$c) return ['skipped', 'Conversation no longer exists'];
            if ($c['status'] !== 'ai') return ['skipped', 'A team member has taken over this chat'];
            if ((int)Db::val("SELECT MAX(id) FROM messages WHERE conversation_id=? AND sender='customer'", [$c['id']]) !== (int)$run['subject_id']) return ['skipped', 'The customer wrote again'];
            $contact = Db::one("SELECT * FROM contacts WHERE id=? AND tenant_id=?", [$c['contact_id'], $tid]);
            $channel = $c['channel_id'] ? Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=? AND status='active'", [$c['channel_id'], $tid]) : null;
            if (!$contact) return ['skipped', 'Contact was deleted'];
            if ($phone !== '' && (int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=? AND phone=? AND created_at >= ?", [$tid, $phone, $c['created_at']])) return ['skipped', 'The customer already ordered'];
            $sum = (string)Db::val("SELECT request_summary FROM tool_logs WHERE conversation_id=? AND tool IN ('search_products','get_product') ORDER BY id DESC LIMIT 1", [$c['id']]);
            $args = json_decode((string)substr($sum, (int)strpos($sum, ' ') + 1), true) ?: [];
            $ctx += ['interest' => (string)($args['search'] ?? $args['sku'] ?? 'our products'), 'name' => (string)$contact['name']];
        }
        if ($phone !== '' && (int)Db::val("SELECT COUNT(*) FROM automation_runs WHERE automation_id=? AND phone=? AND status='sent' AND done_at > NOW() - INTERVAL 1 DAY AND id<>?", [$a['id'], $phone, $run['id']]) && $trig === 'chat_idle') return ['skipped', 'Already followed up with this customer today'];

        // ---- who to send to, and through which channel
        if (!$contact && $phone !== '') $contact = Store::contactByPhone($tid, $phone);
        if (!$channel) {
            $cid = (int)($a['channel_id'] ?: ($contact['channel_id'] ?? 0));
            $channel = $cid ? Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=? AND status='active'", [$cid, $tid]) : null;
            if (!$channel) $channel = Db::one("SELECT * FROM channels WHERE tenant_id=? AND type IN ('whatsapp','twilio_whatsapp') AND status='active' ORDER BY id LIMIT 1", [$tid]);
        }
        if (!$channel) return self::smsFallback($a, $tid, $phone, trim((string)$a['body']), $ctx + ['name' => 'there'], $contact, $trig) ?: ['skipped', 'No active channel to send from'];
        if ($trig === 'lead_created') { // the person asked to be contacted by filling in the form
            if (!$contact) $contact = Chat::contact($tid, (int)$channel['id'], $channel['type'], Store::digits($phone), (string)($ctx['name'] ?? '') ?: null, $phone);
            if ($contact['consent_status'] === 'unknown') { Db::update('contacts', ['consent_status' => 'opted_in', 'consent_source' => 'lead_ad_form', 'consent_at' => now()], 'id=? AND tenant_id=?', [$contact['id'], $tid]); $contact['consent_status'] = 'opted_in'; }
        }
        $ctx['name'] = Agent::safeName((string)($ctx['name'] ?? '')) ?: (Agent::safeName((string)($contact['name'] ?? '')) ?: 'there'); if ($ctx['name'] === 'Customer') $ctx['name'] = 'there';

        // ---- pick free text (inside the 24-hour window) or an approved template
        $last = $contact ? (string)Db::val("SELECT MAX(last_customer_at) FROM conversations WHERE tenant_id=? AND contact_id=?", [$tid, $contact['id']]) : '';
        $inWindow = $last !== '' && ($nowTs - strtotime($last . ' UTC')) < self::WINDOW_SECONDS;
        $tpl = $a['template_id'] ? Db::one("SELECT * FROM wa_templates WHERE id=? AND tenant_id=?", [$a['template_id'], $tid]) : null;
        $tplOk = $tpl && $tpl['status'] === 'approved' && $channel['type'] === 'whatsapp';
        $text = trim((string)$a['body']);
        $useText = $text !== '' && ($inWindow || !$tplOk);
        if ($useText && !$inWindow && ($f = self::smsFallback($a, $tid, $phone, $text, $ctx, $contact, $trig))) return $f;
        if ($useText && !$inWindow) return ['skipped', $tpl && $tpl['status'] !== 'approved' ? "Outside the 24-hour reply window, and template '{$tpl['name']}' is {$tpl['status']} (not approved yet)." : 'Outside the 24-hour reply window. Attach an approved WhatsApp template to reach this customer.'];
        if (!$useText && !$tplOk && ($f = self::smsFallback($a, $tid, $phone, $text, $ctx, $contact, $trig))) return $f;
        if (!$useText && !$tplOk) return ['skipped', $tpl ? "Template '{$tpl['name']}' is " . $tpl['status'] . ' (not approved yet)' : 'No message text or approved template'];
        $marketing = in_array($trig, self::MARKETING, true) || (!$useText && $tpl['category'] === 'marketing');
        if ($contact && $contact['consent_status'] === 'opted_out' && $marketing) return ['skipped', 'Customer opted out of promotional messages'];
        if ($marketing && (!$contact || $contact['consent_status'] !== 'opted_in')) return ['skipped', 'No marketing consent recorded (Contacts > Marketing consent)'];

        $to = $contact ? (string)($contact['phone'] ?: $contact['external_id']) : $phone;
        if ($to === '') return ['skipped', 'No phone number'];
        $body = $useText ? Templates::render($text, $ctx) : Templates::render((string)$tpl['body'], $ctx);
        $res = $useText ? Meta::send($channel, $to, $body) : Templates::send($channel, $to, $tpl, $ctx);
        if (!$res['ok']) return ['failed', (string)($res['error'] ?? 'send failed')];

        // ---- show it in the inbox so the team and the AI both know what the customer was told
        try {
            if (!$contact) $contact = Chat::contact($tid, (int)$channel['id'], $channel['type'], Store::digits($phone), (string)($ctx['name'] ?? '') !== 'there' ? $ctx['name'] : null, $phone);
            $lock = 'conv:' . $tid . ':' . $contact['id'];
            if (Db::lock($lock, 3)) {
                try { $conv = Chat::conversation($tid, $channel, $channel['type'], $contact, $channel['agent_id'] ? (int)$channel['agent_id'] : null); Chat::addMessage($tid, (int)$conv['id'], 'out', 'ai', $body, ['automation' => (int)$a['id'], 'trigger' => $trig, 'template' => $useText ? null : $tpl['name']]); }
                finally { Db::unlock($lock); }
            }
        } catch (\Throwable $e) { Log::exception($e, 'automation_record_failed'); }
        return ['sent', null];
    }

    /** Send the follow-up by SMS when WhatsApp cannot reach the customer and the follow-up allows it. @return array{0:string,1:?string}|null */
    private static function smsFallback(array $a, int $tid, string $phone, string $text, array $ctx, ?array $contact, string $trig): ?array {
        if (empty($a['sms_fallback']) || $text === '' || !Sms::enabled($tid) || strlen($phone) < 9) return null;
        if (in_array($trig, self::MARKETING, true) && (!$contact || $contact['consent_status'] !== 'opted_in')) return ['skipped', 'No marketing consent for SMS either'];
        if ($contact && $contact['consent_status'] === 'opted_out' && in_array($trig, self::MARKETING, true)) return ['skipped', 'Customer opted out of promotional messages'];
        $r = Sms::send($tid, $phone, Templates::render($text, $ctx));
        return $r['ok'] ? ['sent', 'Sent by SMS because WhatsApp could not be used'] : ['failed', (string)$r['error']];
    }

    // ------------------------------------------------------------------ setup helpers
    /** Add the recommended set (idempotent, matched by name). Templates with the standard names are linked when they exist. @return int created */
    public static function installRecommended(int $tid): int {
        $tpl = fn(string $n) => ($id = Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name=? ORDER BY (status='approved') DESC, id LIMIT 1", [$tid, $n])) ? (int)$id : null;
        $set = [
            ['Order confirmation (staff-placed orders)', 'order_placed', 0, "Hi {name}, thank you for your order {order_no} from {shop}. Total {total}. We will message you when it ships.", 'order_confirmed', ['source' => 'manual']],
            ['Order shipped', 'order_shipped', 0, "Good news {name}! Your order {order_no} is on its way with {courier}. Tracking: {tracking}. Expected: {eta}.", 'order_shipped', []],
            ['Order delivered', 'order_delivered', 0, "Hi {name}, your order {order_no} was delivered. If anything is not right, just reply here and we will fix it.", 'order_delivered', []],
            ['Payment reminder', 'payment_pending', 360, "Hi {name}, we are still waiting for the payment of {total} for order {order_no}. Need help paying? Reply here.", 'payment_reminder', []],
            ['Ask for a review', 'review_request', 1440, "Hi {name}, thanks for shopping with {shop}! How was order {order_no}? Reply with a rating from 1 to 5.", 'review_request', []],
            ['Still interested?', 'chat_idle', 120, "Hi {name}, you were looking at {interest} earlier. Would you like me to help you place the order?", 'still_interested', []],
            ['Lead not followed up', 'lead_stale', 240, null, null, []],
            ['New ad lead welcome', 'lead_created', 0, "Hi {name}, thanks for your interest in {interest}. {shop} will contact you shortly. Reply here if you have any questions.", 'lead_welcome', []],
            ['Appointment confirmation', 'appointment_booked', 0, "Hi {name}, your {service} appointment at {shop} is booked for {date} at {time}. Reply here if you need to change it.", 'appointment_confirmed', []],
            ['Appointment reminder', 'appointment_reminder', 180, "Reminder: your {service} appointment at {shop} is today at {time}. Reply here if you cannot make it.", 'appointment_reminder', []],
        ];
        $n = 0;
        foreach ($set as [$name, $trig, $delay, $body, $tplName, $cond]) {
            if (Db::val("SELECT id FROM automations WHERE tenant_id=? AND name=?", [$tid, $name])) continue;
            Db::insert('automations', ['tenant_id' => $tid, 'name' => $name, 'trigger_type' => $trig, 'delay_minutes' => $delay, 'body' => $body, 'template_id' => $tplName ? $tpl($tplName) : null, 'conditions' => $cond ? json_encode($cond) : null, 'is_active' => 1]);
            $n++;
        }
        return $n;
    }

    /** Draft the starter templates (idempotent). @return int created */
    public static function installStarterTemplates(int $tid): int {
        $n = 0;
        foreach (Templates::starters() as [$name, $cat, $body, $footer, $buttons]) {
            $n += Db::q("INSERT IGNORE INTO wa_templates (tenant_id, name, language, category, body, footer, buttons, status) VALUES (?,?,?,?,?,?,?,'draft')", [$tid, $name, 'en', $cat, $body, $footer, $buttons])->rowCount();
        }
        return $n;
    }
}
