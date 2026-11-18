<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$title = 'Contacts';

// Data export (privacy request): one contact's complete data as JSON.
if (isset($_GET['export']) && Auth::isOwner()) {
    $c = Db::one("SELECT * FROM contacts WHERE id=? AND tenant_id=?", [(int)$_GET['export'], $tenantId]);
    if ($c) {
        $convs = Db::all("SELECT * FROM conversations WHERE contact_id=? AND tenant_id=?", [$c['id'], $tenantId]);
        $out = ['exported_at' => gmdate('c'), 'contact' => $c, 'conversations' => $convs,
            'messages' => Db::all("SELECT m.id, m.conversation_id, m.direction, m.sender, m.content, m.created_at FROM messages m JOIN conversations v ON v.id=m.conversation_id WHERE v.contact_id=? AND m.tenant_id=? AND m.sender<>'note' ORDER BY m.id", [$c['id'], $tenantId]),
            'ai_memory' => Db::all("SELECT kind, content, created_at FROM memories WHERE contact_id=? AND tenant_id=?", [$c['id'], $tenantId])];
        Audit::log('contact_export', 'contact:' . $c['id']);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="contact-' . (int)$c['id'] . '.json"');
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)post('id'); $act = post('act');
    if ($act === 'consent') {
        $st = in_array(post('status'), ['unknown', 'opted_in', 'opted_out'], true) ? post('status') : 'unknown';
        Db::update('contacts', ['consent_status' => $st, 'consent_source' => $st === 'opted_in' ? mb_substr(post('source', 'recorded by team'), 0, 80) : 'team', 'consent_at' => now()], 'id=? AND tenant_id=?', [$id, $tenantId]);
        Audit::log('consent_change', 'contact:' . $id, $st . ' / ' . post('source')); flash('Marketing consent updated.');
    } elseif ($act === 'forget') {
        Auth::requireOwner();
        Db::q("DELETE FROM memories WHERE contact_id=? AND tenant_id=?", [$id, $tenantId]);
        Audit::log('memory_erase', 'contact:' . $id); flash('AI memory for this contact was erased.');
    } elseif ($act === 'delete') {
        Auth::requireOwner();
        if (Db::q("DELETE FROM contacts WHERE id=? AND tenant_id=?", [$id, $tenantId])->rowCount()) { Audit::log('contact_delete', 'contact:' . $id); flash('Contact and all their conversations, messages and memories were permanently deleted.'); }
    }
    redirect('app/contacts');
}
$q = trim($_GET['q'] ?? '');
$w = "c.tenant_id=? AND c.channel_type<>'playground'"; $p = [$tenantId];
if ($q !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.external_id LIKE ?)'; $p = array_merge($p, [$like, $like, $like]); }
$rows = Db::all("SELECT c.*, (SELECT COUNT(*) FROM conversations v WHERE v.contact_id=c.id) convs, (SELECT COUNT(*) FROM memories m WHERE m.contact_id=c.id) mems FROM contacts c WHERE $w ORDER BY c.last_seen DESC LIMIT 200", $p);
$owner = Auth::isOwner();
?>
<div class="page-head"><div><h1>Customer <span class="accent">Contacts</span></h1><p>Everyone who has messaged you, their marketing consent, and what the AI remembers. Owners can export or permanently delete a person's data.</p></div></div>
<div class="card"><?php if (!$rows): ?><div class="empty">No contacts yet. Real customers appear here after their first message.</div><?php else: ?>
<div class="tablewrap"><table><thead><tr><th>Name</th><th>Channel</th><th>Phone / ID</th><th class="num">Chats</th><th class="num">Memories</th><th>Last seen</th><th>Marketing consent</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><td><b><?= e($c['name'] ?: '—') ?></b></td><td><span class="badge"><?= e($c['channel_type']) ?></span></td><td class="mono"><?= e(phone_view($c['phone'] ?: $c['external_id'])) ?></td><td class="num"><?= $c['convs'] ?></td><td class="num"><?= $c['mems'] ?></td><td class="muted small"><?= ago($c['last_seen']) ?></td>
<td><form method="post" style="display:flex;gap:6px;align-items:center;min-width:240px"><?= csrf_field() ?><input type="hidden" name="act" value="consent"><input type="hidden" name="id" value="<?= $c['id'] ?>">
  <select name="status" aria-label="Consent status" style="padding:6px 10px"><?php foreach (['unknown' => 'Not recorded', 'opted_in' => 'Opted in', 'opted_out' => 'Opted out'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['consent_status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <input name="source" placeholder="how consent was given" value="<?= e($c['consent_source']) ?>" aria-label="Consent source" style="padding:6px 10px"><button class="btn sm">Save</button></form></td>
<td style="white-space:nowrap"><?php if ($owner): ?><a class="btn sm" href="<?= url('app/contacts?export=' . $c['id']) ?>">Export</a>
  <form method="post" style="display:inline" data-confirm="Erase everything the AI remembers about this contact?"><?= csrf_field() ?><input type="hidden" name="act" value="forget"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm">Forget AI memory</button></form>
  <form method="post" style="display:inline" data-confirm="PERMANENTLY delete this contact with all their conversations, messages and memories? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm danger">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<p class="muted small" style="margin-top:12px">Customers can also reply STOP (or the Sinhala/Tamil equivalents) in a chat to opt out automatically. Campaigns only reach contacts marked “Opted in”.</p>
