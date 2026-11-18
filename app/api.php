<?php
declare(strict_types=1);
/** JSON API for the dashboard UI (session + CSRF protected, always tenant scoped). */

use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Core\Tenant;
use CaliChat\Services\Chat;
use CaliChat\Services\Meta;
use CaliChat\Services\Notifier;
use CaliChat\Services\Queue;
use CaliChat\Services\ToolRunner;

$u = Auth::user();
if (!$u) json_out(['error' => 'unauthenticated'], 401);
$tid = Auth::tenantId();
if (!$tid) json_out(['error' => 'no tenant'], 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();
$action = ($seg[1] ?? '') . '/' . ($seg[2] ?? '');
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$tenant = Tenant::get($tid);
set_tz($tenant['timezone']);
Log::with(['tenant' => $tid, 'user' => (int)$u['id']]);
session_write_close(); // don't block other requests while the AI thinks
set_time_limit(60);
$owner = in_array($u['role'], ['owner', 'super_admin'], true);
$needOwner = function () use ($owner) { if (!$owner) json_out(['error' => 'Owners only'], 403); };
$uidInt = (int)$u['id']; $showPhone = Auth::can('show_phone'); $seeAll = Auth::can('inbox_all');
if ((strpos($action, 'inbox/') === 0 || strpos($action, 'canned/') === 0 || strpos($action, 'labels/') === 0) && !Auth::can('inbox')) json_out(['error' => 'Your account has no access to the inbox'], 403);
$maskV = fn($v) => ($showPhone || $v === null || $v === '') ? $v : \CaliChat\Core\Perms::mask((string)$v);
/** Team members without "see every chat" only reach chats assigned to them or still unassigned. */
$mayAccess = function (int $id) use ($tid, $seeAll, $uidInt): bool { if ($seeAll) return true; $a = Db::val("SELECT assigned_to FROM conversations WHERE id=? AND tenant_id=?", [$id, $tid]); return $a === null || (int)$a === $uidInt; };

switch ($action) {
    case 'catalogue/link': { // the URL a dashboard chat card opens in its modal: a read-only preview, or (owners, Playground with write actions on) the customer's own link
        $sku = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($in['sku'] ?? ''))); $cat = mb_substr(trim((string)($in['category'] ?? '')), 0, 80);
        if ($sku !== '' && !Db::val("SELECT id FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, $sku])) $sku = '';
        if (!empty($in['live'])) {
            $needOwner(); $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? '')); if (strlen($phone) < 9) json_out(['error' => 'A phone number is needed'], 422);
            Audit::log('catalogue_live_link', 'as:' . $phone, '', $tid); $url = \CaliChat\Services\Catalogue::link($tid, $phone, $cat ?: null, 3600, $sku ?: null);
        } else $url = \CaliChat\Services\Catalogue::previewLink($tid, $cat ?: null, $sku ?: null);
        $p = parse_url($url); json_out(['url' => url(ltrim(preg_replace('#^' . preg_quote(base_path(), '#') . '#', '', (string)$p['path']), '/')) . (isset($p['query']) ? '?' . $p['query'] : ''), 'preview' => empty($in['live'])]);
    }

    case 'playground/send': {
        $needOwner();
        $text = trim((string)($in['text'] ?? ''));
        $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? '94771234567')) ?: '94771234567';
        if (!empty($in['reset'])) {
            $c = Db::one("SELECT id FROM contacts WHERE tenant_id=? AND channel_type='playground' AND external_id=?", [$tid, 'pg-' . $u['id'] . '-' . $phone]);
            if ($c) { Db::q("UPDATE conversations SET status='closed' WHERE tenant_id=? AND contact_id=?", [$tid, $c['id']]); Db::q("DELETE FROM memories WHERE tenant_id=? AND contact_id=?", [$tid, $c['id']]); }
            json_out(['ok' => true]);
        }
        if ($text === '' || mb_strlen($text) > 2000) json_out(['error' => 'Message required (max 2000 chars)'], 422);
        $writes = !empty($in['writes']);
        Audit::log('playground_message', 'as:' . $phone, ($writes ? '[writes ON] ' : '') . mb_substr($text, 0, 120), $tid);
        $rid = (string)($in['reply_id'] ?? ''); json_out(Chat::playground($tenant, $u, $text, $phone, $writes, in_array($rid, ['order_confirm', 'order_cancel'], true) ? $rid : null));
    }

    case 'inbox/list': {
        $f = $_GET['status'] ?? 'all'; $q = trim((string)($_GET['q'] ?? '')); $mine = !empty($_GET['mine']);
        $labelId = (int)($_GET['label'] ?? 0);
        $limit = max(20, min(200, (int)($_GET['limit'] ?? 40)));
        $where = "c.tenant_id=? AND c.is_test=0"; $p = [$tid];
        if (in_array($f, ['ai', 'human', 'closed'], true)) { $where .= " AND c.status=?"; $p[] = $f; }
        if ($f === 'unread') $where .= " AND (c.unread > 0 OR c.marked_unread = 1)";
        if ($f === 'pinned') $where .= " AND c.pinned = 1";
        if ($labelId) { $where .= " AND EXISTS (SELECT 1 FROM conversation_labels cl WHERE cl.conversation_id=c.id AND cl.label_id=?)"; $p[] = $labelId; }
        if (!$seeAll) { $where .= " AND (c.assigned_to=? OR c.assigned_to IS NULL)"; $p[] = $uidInt; }
        if ($mine) { $where .= " AND c.assigned_to=?"; $p[] = $u['id']; }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 60)) . '%';
            $where .= " AND (ct.name LIKE ? OR ct.phone LIKE ? OR ct.external_id LIKE ? OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id=c.id AND m.tenant_id=c.tenant_id AND m.content LIKE ?))";
            array_push($p, $like, $like, $like, $like);
        }
        $rows = Db::all("SELECT c.id, c.status, c.unread, c.pinned, c.marked_unread, c.last_preview, c.last_message_at, c.channel_type, c.assigned_to, c.last_customer_at, c.last_human_at, ct.name, ct.phone, ct.external_id, au.name assignee
            FROM conversations c JOIN contacts ct ON ct.id=c.contact_id LEFT JOIN users au ON au.id=c.assigned_to
            WHERE $where ORDER BY c.pinned DESC, c.last_message_at DESC, c.id DESC LIMIT $limit", $p);
        $byConv = [];
        if ($rows) foreach (Db::all("SELECT cl.conversation_id cid, l.id, l.name, l.color FROM conversation_labels cl JOIN labels l ON l.id=cl.label_id WHERE l.tenant_id=? AND cl.conversation_id IN (" . implode(',', array_map('intval', array_column($rows, 'id'))) . ")", [$tid]) as $lr) $byConv[(int)$lr['cid']][] = ['id' => (int)$lr['id'], 'name' => $lr['name'], 'color' => $lr['color']];
        foreach ($rows as &$r) {
            $r['labels'] = $byConv[(int)$r['id']] ?? []; $r['pinned'] = (int)$r['pinned']; if ((int)$r['marked_unread'] && (int)$r['unread'] === 0) $r['unread'] = 1;
            $r['phone'] = $maskV($r['phone']); $r['external_id'] = $maskV($r['external_id']);
            $r['ago'] = ago($r['last_message_at']); $r['title'] = $r['name'] ?: ($r['phone'] ?: $r['external_id']);
            $d = $r['last_message_at'] ? local_dt($r['last_message_at'], 'M j') : ''; // WhatsApp style: the time today, "Yesterday", then the date
            $r['time_label'] = !$r['last_message_at'] ? '' : ($d === local_dt(now(), 'M j') ? local_dt($r['last_message_at'], 'H:i') : ($d === local_dt(gmdate('Y-m-d H:i:s', time() - 86400), 'M j') ? 'Yesterday' : $d));
            $r['waiting_s'] = ($r['status'] === 'human' && $r['last_customer_at'] && (!$r['last_human_at'] || $r['last_customer_at'] > $r['last_human_at'])) ? max(0, time() - strtotime($r['last_customer_at'] . ' UTC')) : null;
        }
        json_out(['conversations' => $rows]);
    }

    case 'inbox/poll': {
        $r = Db::one("SELECT (SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND status='human' AND is_test=0) human,
                             (SELECT COALESCE(SUM(unread),0) FROM conversations WHERE tenant_id=? AND is_test=0) unread,
                             (SELECT COALESCE(MAX(id),0) FROM messages WHERE tenant_id=? AND sender='customer') latest", [$tid, $tid, $tid]);
        json_out(['human' => (int)$r['human'], 'unread' => (int)$r['unread'], 'latest' => (int)$r['latest'], 'alerts' => Notifier::unseenCount($tid)]);
    }

    case 'inbox/thread': {
        $id = (int)($_GET['id'] ?? 0); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        $c = Db::one("SELECT c.*, ct.name, ct.phone, ct.external_id, ct.consent_status, au.name assignee FROM conversations c JOIN contacts ct ON ct.id=c.contact_id LEFT JOIN users au ON au.id=c.assigned_to WHERE c.id=? AND c.tenant_id=?", [$id, $tid]);
        if (!$c) json_out(['error' => 'not found'], 404);
        $after = (int)($_GET['after'] ?? 0); $before = (int)($_GET['before'] ?? 0);
        if ($before) $msgs = array_reverse(Db::all("SELECT id, direction, sender, content, meta, media_type, created_at FROM messages WHERE conversation_id=? AND tenant_id=? AND id<? ORDER BY id DESC LIMIT 50", [$id, $tid, $before]));
        elseif ($after) $msgs = Db::all("SELECT id, direction, sender, content, meta, media_type, created_at FROM messages WHERE conversation_id=? AND tenant_id=? AND id>? ORDER BY id ASC LIMIT 200", [$id, $tid, $after]);
        else $msgs = array_reverse(Db::all("SELECT id, direction, sender, content, meta, media_type, created_at FROM messages WHERE conversation_id=? AND tenant_id=? ORDER BY id DESC LIMIT 60", [$id, $tid]));
        foreach ($msgs as &$m) { $m['time'] = local_dt($m['created_at'], 'M j, H:i'); $m['meta'] = json_decode((string)$m['meta'], true); unset($m['meta']['tool_notes']); }
        if ($c['unread'] > 0 || $c['marked_unread']) Db::update('conversations', ['unread' => 0, 'marked_unread' => 0], 'id=? AND tenant_id=?', [$id, $tid]);
        $threadLabels = Db::all("SELECT l.id, l.name, l.color FROM conversation_labels cl JOIN labels l ON l.id=cl.label_id WHERE cl.conversation_id=? AND l.tenant_id=?", [$id, $tid]);
        $oldest = $msgs ? (int)$msgs[0]['id'] : 0;
        json_out(['conversation' => ['id' => $c['id'], 'status' => $c['status'], 'title' => $c['name'] ?: ($maskV($c['phone']) ?: $maskV($c['external_id'])), 'pinned' => (int)$c['pinned'], 'labels' => $threadLabels, 'channel' => $c['channel_type'], 'handoff_reason' => $c['handoff_reason'],
            'assignee' => $c['assignee'], 'assigned_to' => $c['assigned_to'], 'consent' => $c['consent_status'], 'summary' => $c['summary']],
            'today' => local_dt(now(), 'M j'), 'yesterday' => local_dt(gmdate('Y-m-d H:i:s', time() - 86400), 'M j'), 'messages' => $msgs, 'has_more' => $oldest > 0 && !$after && (int)Db::val("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND tenant_id=? AND id<?", [$id, $tid, $oldest]) > 0]);
    }

    case 'inbox/reply': {
        $id = (int)($in['id'] ?? 0); $text = trim((string)($in['text'] ?? '')); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        $c = Db::one("SELECT c.*, ct.external_id FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.id=? AND c.tenant_id=?", [$id, $tid]);
        if (!$c || $text === '' || mb_strlen($text) > 4000) json_out(['error' => 'invalid'], 422);
        $meta = [];
        if ($c['channel_id']) {
            $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [$c['channel_id'], $tid]);
            $d = $ch ? Meta::send($ch, $c['external_id'], $text) : ['ok' => false, 'error' => 'Channel missing'];
            if (!$d['ok']) $meta['delivery_error'] = $d['error'];
        }
        Db::update('conversations', ['status' => 'human', 'assigned_to' => $c['assigned_to'] ?: $u['id']], 'id=? AND tenant_id=?', [$id, $tid]);
        Db::q("UPDATE handoffs SET resolved_at=COALESCE(resolved_at, NOW()), resolved_by=COALESCE(resolved_by,'human') WHERE conversation_id=? AND tenant_id=? AND resolved_at IS NULL", [$id, $tid]);
        Chat::addMessage($tid, $id, 'out', 'human', $text, $meta + ['user' => $u['name']]);
        json_out(['ok' => empty($meta['delivery_error']), 'error' => $meta['delivery_error'] ?? null]);
    }

    case 'inbox/note': {
        $id = (int)($in['id'] ?? 0); $text = trim((string)($in['text'] ?? '')); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        if (!Db::val("SELECT id FROM conversations WHERE id=? AND tenant_id=?", [$id, $tid]) || $text === '') json_out(['error' => 'invalid'], 422);
        Chat::addMessage($tid, $id, 'out', 'note', mb_substr($text, 0, 2000), ['user' => $u['name']]);
        json_out(['ok' => true]);
    }

    case 'inbox/assign': {
        $id = (int)($in['id'] ?? 0); $to = (int)($in['user_id'] ?? 0); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        if ($to && !Db::val("SELECT id FROM users WHERE id=? AND tenant_id=? AND status='active'", [$to, $tid])) json_out(['error' => 'invalid user'], 422);
        Db::update('conversations', ['assigned_to' => $to ?: null], 'id=? AND tenant_id=?', [$id, $tid]);
        json_out(['ok' => true]);
    }

    case 'inbox/status': {
        $id = (int)($in['id'] ?? 0); $st = (string)($in['status'] ?? ''); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        if (!in_array($st, ['ai', 'human', 'closed'], true)) json_out(['error' => 'invalid'], 422);
        $c = Db::one("SELECT status, handoff_reason, is_test FROM conversations WHERE id=? AND tenant_id=?", [$id, $tid]);
        if (!$c) json_out(['error' => 'not found'], 404);
        $set = ['status' => $st, 'handoff_reason' => $st === 'human' ? $c['handoff_reason'] : null];
        if ($st === 'ai' && $c['status'] !== 'ai') { // handed back to the AI: keep an optional note for it, and answer a customer message that is still waiting
            $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['note'] ?? ''))), 0, 600);
            $lastSender = (string)Db::val("SELECT sender FROM messages WHERE conversation_id=? AND tenant_id=? AND sender IN ('customer','ai','human') ORDER BY id DESC LIMIT 1", [$id, $tid]);
            $set += ['handback_note' => $note !== '' ? $note : null, 'ai_catchup' => ($lastSender === 'customer' && !(int)$c['is_test']) ? 1 : 0];
            Chat::addMessage($tid, $id, 'out', 'system', 'Handed back to the AI by ' . $u['name'] . ($note !== '' ? '. Note for the AI: ' . $note : ''));
        }
        Db::update('conversations', $set, 'id=? AND tenant_id=?', [$id, $tid]);
        if ($st !== 'human') Db::q("UPDATE handoffs SET resolved_at=COALESCE(resolved_at, NOW()), resolved_by=COALESCE(resolved_by,?) WHERE conversation_id=? AND tenant_id=? AND resolved_at IS NULL", [$st === 'ai' ? 'ai_resumed' : 'closed', $id, $tid]);
        json_out(['ok' => true]);
    }

    case 'inbox/pin': {
        $id = (int)($in['id'] ?? 0); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        $on = !empty($in['pinned']);
        if ($on && (int)Db::val("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND pinned=1", [$tid]) >= 5) json_out(['error' => 'You can pin up to 5 chats. Unpin one first.'], 422);
        Db::q("UPDATE conversations SET pinned=? WHERE id=? AND tenant_id=?", [$on ? 1 : 0, $id, $tid]); json_out(['ok' => true]);
    }

    case 'inbox/unread': {
        $id = (int)($in['id'] ?? 0); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        Db::q("UPDATE conversations SET marked_unread=1 WHERE id=? AND tenant_id=?", [$id, $tid]); json_out(['ok' => true]);
    }

    case 'inbox/label': { // put a label on a chat, or take it off
        $id = (int)($in['id'] ?? 0); $lid = (int)($in['label_id'] ?? 0); if (!$mayAccess($id)) json_out(['error' => 'not found'], 404);
        if (!Db::val("SELECT id FROM conversations WHERE id=? AND tenant_id=?", [$id, $tid]) || !Db::val("SELECT id FROM labels WHERE id=? AND tenant_id=?", [$lid, $tid])) json_out(['error' => 'not found'], 404);
        if (!empty($in['on'])) Db::q("INSERT IGNORE INTO conversation_labels (conversation_id, label_id) VALUES (?,?)", [$id, $lid]); else Db::q("DELETE FROM conversation_labels WHERE conversation_id=? AND label_id=?", [$id, $lid]);
        json_out(['ok' => true]);
    }

    case 'labels/list': {
        json_out(['labels' => Db::all("SELECT id, name, color FROM labels WHERE tenant_id=? ORDER BY name", [$tid])]);
    }

    case 'labels/save': {
        $needOwner(); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 40); $col = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#5B5BF0';
        if ($name === '') json_out(['error' => 'A name is required'], 422);
        if ((int)Db::val("SELECT COUNT(*) FROM labels WHERE tenant_id=?", [$tid]) >= 30) json_out(['error' => 'At most 30 labels'], 422);
        Db::q("INSERT INTO labels (tenant_id, name, color) VALUES (?,?,?) ON DUPLICATE KEY UPDATE color=VALUES(color)", [$tid, $name, $col]); json_out(['ok' => true, 'id' => (int)Db::val("SELECT id FROM labels WHERE tenant_id=? AND name=?", [$tid, $name])]);
    }

    case 'labels/delete': {
        $needOwner(); Db::q("DELETE FROM labels WHERE id=? AND tenant_id=?", [(int)($in['id'] ?? 0), $tid]); json_out(['ok' => true]);
    }

    case 'canned/list': {
        json_out(['replies' => Db::all("SELECT id, title, body FROM canned_replies WHERE tenant_id=? ORDER BY title", [$tid])]);
    }

    case 'tools/test': {
        $needOwner();
        $tool = Db::one("SELECT * FROM tools WHERE id=? AND tenant_id=?", [(int)($in['tool_id'] ?? 0), $tid]);
        if (!$tool) json_out(['error' => 'not found'], 404);
        $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
        $r = ToolRunner::run($tool, (array)($in['args'] ?? []), ['phone' => $phone, 'external_id' => $phone, 'name' => 'Test'], null, ['test' => true]);
        Audit::log('tool_test', 'tool:' . $tool['name'], 'ok=' . (int)$r['ok'] . ' phone=' . $phone, $tid);
        json_out(['ok' => $r['ok'], 'status' => $r['status'], 'ms' => $r['ms'], 'error' => $r['error'], 'cache_hit' => $r['cache_hit'], 'data' => $r['data']]);
    }

    case 'channels/verify': {
        $needOwner();
        $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [(int)($in['id'] ?? 0), $tid]);
        if (!$ch) json_out(['error' => 'not found'], 404);
        $r = Meta::verifyConnection($ch);
        Db::update('channels', ['verified_at' => $r['ok'] ? now() : null, 'verified_name' => $r['name'], 'verify_error' => $r['error'] ? mb_substr($r['error'], 0, 290) : null], 'id=? AND tenant_id=?', [$ch['id'], $tid]);
        json_out($r);
    }

    case 'alerts/resolve': {
        $id = (int)($in['id'] ?? 0);
        if ($id) Db::q("UPDATE alerts SET resolved_at=NOW(), seen=1 WHERE id=? AND tenant_id=?", [$id, $tid]);
        else Db::q("UPDATE alerts SET seen=1 WHERE tenant_id=? AND resolved_at IS NULL", [$tid]);
        json_out(['ok' => true]);
    }

    case 'meta/wa_signup': { // WhatsApp Embedded Signup finished in the browser: code + WABA id + phone number id
        $needOwner();
        if (!\CaliChat\Services\MetaConnect::whatsappReady()) json_out(['error' => 'WhatsApp one-click signup is not enabled on this platform'], 400);
        $code = (string)($in['code'] ?? ''); $pn = preg_replace('/\D/', '', (string)($in['phone_number_id'] ?? '')); $waba = preg_replace('/\D/', '', (string)($in['waba_id'] ?? ''));
        if ($code === '' || $pn === '' || $waba === '') json_out(['error' => 'Facebook did not return the WhatsApp account details. Please try again and finish every step in the pop-up.'], 422);
        try { $token = \CaliChat\Services\MetaConnect::tokenFromCode($code, false); } catch (\Throwable $e) { json_out(['error' => $e->getMessage()], 502); }
        $sub = \CaliChat\Services\MetaConnect::subscribeWaba($waba, $token);
        if (!$sub['ok']) json_out(['error' => 'Could not subscribe to WhatsApp messages: ' . $sub['error']], 502);
        \CaliChat\Services\MetaConnect::registerNumber($pn, $token); // fine if it is already registered
        $info = \CaliChat\Services\MetaConnect::phoneInfo($pn, $token);
        $name = trim(($info['json']['verified_name'] ?? 'WhatsApp') . ' ' . ($info['json']['display_phone_number'] ?? $pn));
        $cid = \CaliChat\Services\MetaConnect::saveChannel($tid, 'whatsapp', $pn, 'WhatsApp: ' . $name, $token, $waba);
        Audit::log('channel_connect', 'channel:' . $cid, 'whatsapp embedded signup', $tid);
        json_out(['ok' => true, 'name' => $name, 'channel_id' => $cid]);
    }

    case 'slips/file': { // payment slip image, only for the tenant's own staff
        $s = Db::one("SELECT file, mime FROM payment_slips WHERE id=? AND tenant_id=?", [(int)($_GET['id'] ?? 0), $tid]);
        $path = $s ? CaliChat\Services\Slips::dir($tid) . '/' . basename((string)$s['file']) : '';
        if (!$s || !is_file($path) || !in_array($s['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) json_out(['error' => 'not found'], 404);
        header_remove('Content-Type'); header('Content-Type: ' . $s['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=300'); readfile($path); exit;
    }

    case 'actions/decide': {
        $r = \CaliChat\Services\Actions::decide($tid, (int)($in['id'] ?? 0), !empty($in['approve']), $u);
        json_out($r);
    }

    case 'events/requeue': {
        $needOwner();
        json_out(['ok' => Queue::requeue((int)($in['id'] ?? 0), $tid)]);
    }
}
json_out(['error' => 'unknown action'], 404);
