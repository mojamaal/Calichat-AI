<?php
declare(strict_types=1);
namespace CaliChat\Services;

use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

/** Business numbers for the Reports page: what the chats produced (orders, revenue, leads, bookings), how fast and how well they were handled. */
class Reports {
    /** @return array<string,mixed> */
    public static function summary(int $tid, int $days = 30): array {
        $days = max(1, min(365, $days)); $from = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $tz = new \DateTimeZone(Tenant::tz($tid)); $off = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC'))); $offStr = sprintf('%s%02d:%02d', $off < 0 ? '-' : '+', intdiv(abs($off), 3600), intdiv(abs($off) % 3600, 60));
        $r = ['days' => $days, 'from' => $from];

        // ---- conversations
        $c = Db::one("SELECT COUNT(*) total, SUM(channel_type<>'playground') real_total FROM conversations WHERE tenant_id=? AND is_test=0 AND created_at>=?", [$tid, $from]);
        $ids = array_column(Db::all("SELECT id FROM conversations WHERE tenant_id=? AND is_test=0 AND created_at>=? LIMIT 5000", [$tid, $from]), 'id');
        $r['conversations'] = (int)$c['total'];
        $human = array_column(Db::all("SELECT DISTINCT conversation_id id FROM messages WHERE tenant_id=? AND sender='human' AND created_at>=?", [$tid, $from]), 'id');
        $handoff = array_column(Db::all("SELECT DISTINCT conversation_id id FROM handoffs WHERE tenant_id=? AND created_at>=?", [$tid, $from]), 'id');
        $inRange = array_flip($ids); $needHuman = array_unique(array_merge(array_filter($human, fn($i) => isset($inRange[$i])), array_filter($handoff, fn($i) => isset($inRange[$i]))));
        $r['handoffs'] = count($handoff); $r['ai_only'] = max(0, $r['conversations'] - count($needHuman)); $r['ai_only_pct'] = $r['conversations'] ? round(100 * $r['ai_only'] / $r['conversations']) : null;
        $r['messages_in'] = (int)Db::val("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.tenant_id=? AND c.is_test=0 AND m.direction='in' AND m.sender='customer' AND m.created_at>=?", [$tid, $from]);
        $r['ai_replies'] = (int)Db::val("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.tenant_id=? AND c.is_test=0 AND m.sender='ai' AND m.created_at>=?", [$tid, $from]);
        // first response time (customer's first message to the first reply, AI or human)
        $rows = Db::all("SELECT MIN(CASE WHEN m.direction='in' THEN m.created_at END) fin, MIN(CASE WHEN m.direction='out' AND m.sender IN ('ai','human') THEN m.created_at END) fout
                         FROM conversations c JOIN messages m ON m.conversation_id=c.id WHERE c.tenant_id=? AND c.is_test=0 AND c.created_at>=? GROUP BY c.id LIMIT 3000", [$tid, $from]);
        $d = []; foreach ($rows as $x) if ($x['fin'] && $x['fout'] && $x['fout'] >= $x['fin']) $d[] = strtotime($x['fout'] . ' UTC') - strtotime($x['fin'] . ' UTC');
        sort($d); $r['first_response_s'] = $d ? $d[intdiv(count($d), 2)] : null; $r['first_response_p90_s'] = $d ? $d[min(count($d) - 1, (int)floor(count($d) * 0.9))] : null;
        $r['by_channel'] = Db::all("SELECT channel_type, COUNT(*) n FROM conversations WHERE tenant_id=? AND is_test=0 AND created_at>=? GROUP BY channel_type ORDER BY n DESC", [$tid, $from]);

        // ---- how conversations ended
        $ev = fn(string $tool) => array_column(Db::all("SELECT DISTINCT conversation_id id FROM tool_logs WHERE tenant_id=? AND tool=? AND ok=1 AND is_write=1 AND created_at>=? AND conversation_id IS NOT NULL", [$tid, $tool, $from]), 'id');
        $ordered = array_intersect($ids, $ev('create_order')); $lead = array_diff(array_intersect($ids, $ev('create_lead')), $ordered); $appt = array_diff(array_intersect($ids, $ev('book_appointment')), $ordered, $lead);
        $esc = array_diff(array_intersect($ids, $needHuman), $ordered, $lead, $appt); $done = count($ordered) + count($lead) + count($appt) + count($esc);
        $r['outcomes'] = ['Order placed' => count($ordered), 'Lead recorded' => count($lead), 'Appointment booked' => count($appt), 'Needed a person' => count($esc), 'Answered, nothing to record' => max(0, $r['conversations'] - $done)];
        $r['chat_to_order_pct'] = $r['conversations'] ? round(100 * count($ordered) / $r['conversations'], 1) : null;

        // ---- orders and revenue
        $o = Db::all("SELECT source, COUNT(*) n, COALESCE(SUM(total),0) revenue FROM store_orders WHERE tenant_id=? AND created_at>=? AND status NOT IN ('cancelled') GROUP BY source", [$tid, $from]); $by = array_column($o, null, 'source');
        $r['orders_ai'] = (int)($by['ai']['n'] ?? 0); $r['orders_team'] = (int)($by['manual']['n'] ?? 0); $r['revenue_ai'] = (float)($by['ai']['revenue'] ?? 0); $r['revenue_team'] = (float)($by['manual']['revenue'] ?? 0);
        $r['orders_web'] = (int)($by['catalogue']['n'] ?? 0); $r['revenue_web'] = (float)($by['catalogue']['revenue'] ?? 0); // customers who ordered on the catalogue page themselves
        $r['orders'] = $r['orders_ai'] + $r['orders_team'] + $r['orders_web']; $r['revenue'] = $r['revenue_ai'] + $r['revenue_team'] + $r['revenue_web']; $r['aov'] = $r['orders'] ? $r['revenue'] / $r['orders'] : 0;
        $r['orders_cancelled'] = (int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=? AND created_at>=? AND status='cancelled'", [$tid, $from]);
        $r['orders_by_status'] = Db::all("SELECT status, COUNT(*) n FROM store_orders WHERE tenant_id=? AND created_at>=? GROUP BY status", [$tid, $from]);
        $r['top_products'] = Db::all("SELECT i.sku, MAX(i.name) name, SUM(i.qty) qty, SUM(i.qty*i.unit_price) revenue FROM store_order_items i JOIN store_orders o ON o.id=i.order_id WHERE o.tenant_id=? AND o.created_at>=? AND o.status<>'cancelled' GROUP BY i.sku ORDER BY revenue DESC LIMIT 8", [$tid, $from]);
        $series = Db::all("SELECT DATE(CONVERT_TZ(created_at,'+00:00',?)) d, COUNT(*) n, SUM(total) revenue FROM store_orders WHERE tenant_id=? AND created_at>=? AND status<>'cancelled' GROUP BY d", [$offStr, $tid, $from]); $sm = array_column($series, null, 'd');
        $r['daily_orders'] = $r['daily_revenue'] = []; $step = max(1, (int)ceil($days / 30));
        for ($i = $days - 1; $i >= 0; $i -= $step) { $day = (new \DateTime("today -$i days", $tz))->format('Y-m-d'); $n = 0; $rev = 0.0; for ($k = 0; $k < $step; $k++) { $dd = (new \DateTime("$day +$k days", $tz))->format('Y-m-d'); $n += (int)($sm[$dd]['n'] ?? 0); $rev += (float)($sm[$dd]['revenue'] ?? 0); } $lbl = substr($day, 8, 2); $r['daily_orders'][$lbl . ($step > 1 ? '+' : '')] = $n; $r['daily_revenue'][$lbl . ($step > 1 ? '+' : '')] = (int)$rev; }

        // ---- leads
        $r['leads_by_stage'] = array_column(Db::all("SELECT stage, COUNT(*) n FROM crm_leads WHERE tenant_id=? AND created_at>=? GROUP BY stage", [$tid, $from]), 'n', 'stage');
        $r['leads'] = array_sum($r['leads_by_stage']); $won = (int)($r['leads_by_stage']['won'] ?? 0); $r['lead_win_pct'] = $r['leads'] ? round(100 * $won / $r['leads'], 1) : null;
        $r['leads_by_source'] = Db::all("SELECT source, COUNT(*) n, SUM(stage='won') won FROM crm_leads WHERE tenant_id=? AND created_at>=? GROUP BY source ORDER BY n DESC", [$tid, $from]);
        $r['pipeline_value'] = (float)Db::val("SELECT COALESCE(SUM(value),0) FROM crm_leads WHERE tenant_id=? AND stage IN ('new','contacted','qualified')", [$tid]); $r['won_value'] = (float)Db::val("SELECT COALESCE(SUM(value),0) FROM crm_leads WHERE tenant_id=? AND stage='won' AND updated_at>=?", [$tid, $from]);

        // ---- where customers came from
        $r['sources'] = Db::all("SELECT COALESCE(acquisition_source,'organic') src, COUNT(*) n FROM contacts WHERE tenant_id=? AND channel_type<>'playground' AND created_at>=? GROUP BY src ORDER BY n DESC", [$tid, $from]);
        $r['ads'] = Db::all("SELECT acquisition_detail ad, COUNT(*) n FROM contacts WHERE tenant_id=? AND acquisition_source='click_to_whatsapp' AND created_at>=? GROUP BY acquisition_detail ORDER BY n DESC LIMIT 5", [$tid, $from]);

        // ---- appointments, follow-ups, team
        $r['appointments'] = array_column(Db::all("SELECT status, COUNT(*) n FROM appointments WHERE tenant_id=? AND start_at>=? AND start_at<=UTC_TIMESTAMP() + INTERVAL 1 DAY GROUP BY status", [$tid, $from]), 'n', 'status');
        $r['followups'] = Db::all("SELECT a.name, SUM(r.status='sent') sent, SUM(r.status='skipped') skipped, SUM(r.status='failed') failed FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE r.tenant_id=? AND r.created_at>=? GROUP BY a.id, a.name ORDER BY sent DESC", [$tid, $from]);
        $r['team'] = Db::all("SELECT u.name,
              (SELECT COUNT(*) FROM conversations c WHERE c.tenant_id=u.tenant_id AND c.assigned_to=u.id AND c.created_at>=?) chats,
              (SELECT COUNT(*) FROM messages m WHERE m.tenant_id=u.tenant_id AND m.sender='human' AND JSON_UNQUOTE(JSON_EXTRACT(m.meta,'\$.user'))=u.name AND m.created_at>=?) replies,
              (SELECT COUNT(*) FROM crm_leads l WHERE l.tenant_id=u.tenant_id AND l.assigned_to=u.id AND l.created_at>=?) leads,
              (SELECT COUNT(*) FROM crm_leads l WHERE l.tenant_id=u.tenant_id AND l.assigned_to=u.id AND l.stage='won' AND l.updated_at>=?) won
            FROM users u WHERE u.tenant_id=? AND u.status='active' ORDER BY replies DESC, chats DESC", [$from, $from, $from, $from, $tid]);
        return $r;
    }

    /** CSV rows for download. @return array{0:string[],1:array<int,array>} header and rows */
    public static function export(int $tid, string $what, int $days, bool $showPhone): array {
        $from = gmdate('Y-m-d H:i:s', time() - max(1, min(365, $days)) * 86400); $ph = fn($p) => $showPhone ? $p : \CaliChat\Core\Perms::mask((string)$p);
        if ($what === 'orders') {
            $rows = array_map(fn($o) => [$o['order_no'], $o['created_at'], $o['status'], $o['source'], $ph($o['phone']), $o['customer_name'], $o['delivery_city'], $o['payment_method'], $o['payment_status'], $o['subtotal'], $o['delivery_fee'], $o['total']], Db::all("SELECT * FROM store_orders WHERE tenant_id=? AND created_at>=? ORDER BY id", [$tid, $from]));
            return [['order_no', 'placed_at_utc', 'status', 'placed_by', 'phone', 'customer', 'city', 'payment', 'payment_status', 'subtotal', 'delivery', 'total'], $rows];
        }
        if ($what === 'leads') {
            return [['id', 'created_at_utc', 'stage', 'source', 'name', 'phone', 'interest', 'value', 'confirmed_by_customer'], array_map(fn($l) => [$l['id'], $l['created_at'], $l['stage'], $l['source'], $l['name'], $ph($l['phone']), $l['interest'], $l['value'], $l['confirmed_at'] ? 'yes' : 'no'], Db::all("SELECT * FROM crm_leads WHERE tenant_id=? AND created_at>=? ORDER BY id", [$tid, $from]))];
        }
        return [['conversation_id', 'started_utc', 'channel', 'status', 'customer', 'phone', 'messages'], array_map(fn($c) => [$c['id'], $c['created_at'], $c['channel_type'], $c['status'], $c['name'], $ph($c['phone'] ?: $c['external_id']), $c['n']],
            Db::all("SELECT c.id, c.created_at, c.channel_type, c.status, ct.name, ct.phone, ct.external_id, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id) n FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.tenant_id=? AND c.is_test=0 AND c.created_at>=? ORDER BY c.id", [$tid, $from]))];
    }
}
