<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Db;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

$title = 'Leads';
$labels = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'won' => 'Won', 'lost' => 'Lost'];
$team = Db::all("SELECT id, name FROM users WHERE tenant_id=? AND status='active' ORDER BY name", [$tenantId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); \CaliChat\Core\Auth::requireCan('leads_edit');
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'lead_create') {
            $r = Store::createLead($tenantId, ['phone' => post('phone'), 'name' => post('name'), 'interest' => post('interest'), 'preferred_time' => post('preferred_time'), 'notes' => post('notes'), 'value' => post('value')], 'manual');
            flash($r['duplicate'] ? 'That enquiry already exists.' : 'Lead added.', $r['duplicate'] ? 'err' : 'ok'); redirect('app/leads?l=' . $r['lead']['id']);
        }
        if (!Db::val("SELECT id FROM crm_leads WHERE id=? AND tenant_id=?", [$id, $tenantId])) { flash('Lead not found.', 'err'); redirect('app/leads'); }
        if ($act === 'lead_stage') { Store::setStage($tenantId, $id, post('stage'), post('lost_reason')); Audit::log('crm_lead_stage', 'lead:' . $id, post('stage')); redirect('app/leads' . (!empty($_POST['keep']) ? '?l=' . $id : '')); }
        if ($act === 'lead_note') { if (post('body') !== '') Db::insert('crm_lead_notes', ['tenant_id' => $tenantId, 'lead_id' => $id, 'user_id' => $u['id'], 'body' => mb_substr(post('body'), 0, 1000)]); redirect('app/leads?l=' . $id); }
        if ($act === 'lead_assign') { $to = (int)post('assigned_to'); Db::update('crm_leads', ['assigned_to' => Db::val("SELECT id FROM users WHERE id=? AND tenant_id=?", [$to, $tenantId]) ?: null, 'updated_at' => now()], 'id=? AND tenant_id=?', [$id, $tenantId]); redirect('app/leads?l=' . $id); }
        if ($act === 'lead_value') { Db::update('crm_leads', ['value' => post('value') === '' ? null : max(0, (float)post('value')), 'updated_at' => now()], 'id=? AND tenant_id=?', [$id, $tenantId]); flash('Saved.'); redirect('app/leads?l=' . $id); }
    } catch (StoreError $e) { flash($e->getMessage(), 'err'); redirect('app/leads'); }
}

$mine = !empty($_GET['mine']);
$rows = Db::all("SELECT l.*, (SELECT name FROM users WHERE id=l.assigned_to) owner FROM crm_leads l WHERE l.tenant_id=?" . ($mine ? ' AND l.assigned_to=' . (int)$u['id'] : '') . " AND (l.stage NOT IN ('won','lost') OR l.updated_at > NOW() - INTERVAL 30 DAY) ORDER BY l.id DESC LIMIT 400", [$tenantId]);
$by = array_fill_keys(array_keys($labels), []); foreach ($rows as $r) $by[$r['stage']][] = $r;
$open = (float)Db::val("SELECT COALESCE(SUM(value),0) FROM crm_leads WHERE tenant_id=? AND stage IN ('new','contacted','qualified')", [$tenantId]);
$won = (float)Db::val("SELECT COALESCE(SUM(value),0) FROM crm_leads WHERE tenant_id=? AND stage='won' AND updated_at > NOW() - INTERVAL 30 DAY", [$tenantId]);
$sel = !empty($_GET['l']) ? Db::one("SELECT l.*, (SELECT name FROM users WHERE id=l.assigned_to) owner FROM crm_leads l WHERE l.id=? AND l.tenant_id=?", [(int)$_GET['l'], $tenantId]) : null;
$notes = $sel ? Db::all("SELECT n.*, (SELECT name FROM users WHERE id=n.user_id) who FROM crm_lead_notes n WHERE n.lead_id=? ORDER BY n.id DESC", [$sel['id']]) : [];
$orders = $sel ? Db::all("SELECT order_no, status, total, created_at FROM store_orders WHERE tenant_id=? AND phone=? ORDER BY id DESC LIMIT 5", [$tenantId, $sel['phone']]) : [];
?>
<div class="page-head"><div><h1>Sales <span class="accent">Leads</span></h1><p>Enquiries the AI agent records, plus the ones you add. Move each one along until it is won or lost.</p></div>
  <div class="actions"><a class="btn <?= $mine ? '' : 'primary' ?>" href="<?= url('app/leads') ?>">All leads</a><a class="btn <?= $mine ? 'primary' : '' ?>" href="<?= url('app/leads?mine=1') ?>">Assigned to me</a></div></div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:22px">
  <div class="card stat"><div class="muted small">Open pipeline</div><div class="big">Rs. <?= num($open) ?></div><div class="foot"><?= count($by['new']) + count($by['contacted']) + count($by['qualified']) ?> open leads</div></div>
  <div class="card stat"><div class="muted small">Won, last 30 days</div><div class="big">Rs. <?= num($won) ?></div><div class="foot"><?= count($by['won']) ?> leads</div></div>
  <div class="card stat"><div class="muted small">Waiting for a first touch</div><div class="big"><?= count($by['new']) ?></div><div class="foot">stage New</div></div></div>

<div class="board" role="list"><?php foreach ($labels as $k => $l): ?><section class="bcol" aria-label="<?= e($l) ?>"><h3><?= e($l) ?> <span class="badge gray"><?= count($by[$k]) ?></span></h3>
  <?php if (!$by[$k]): ?><div class="muted small" style="padding:8px 2px">Nothing here.</div><?php endif; ?>
  <?php foreach ($by[$k] as $r): ?><div class="lcard" role="listitem"><a href="<?= url('app/leads?l=' . $r['id'] . ($mine ? '&mine=1' : '')) ?>"><b><?= e($r['name']) ?></b></a>
    <div class="small"><?= e($r['interest']) ?></div><div class="muted small mono"><?= e(phone_view($r['phone'])) ?></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0"><?php if ($r['confirmed_at']): ?><span class="badge ok">customer confirmed</span><?php endif; ?><span class="badge gray"><?= $r['source'] === 'ai' ? 'by AI' : 'by team' ?></span><?php if ($r['value'] !== null): ?><span class="badge">Rs. <?= num($r['value']) ?></span><?php endif; ?></div>
    <div class="muted small"><?= ago($r['created_at']) ?><?= $r['owner'] ? ' · ' . e($r['owner']) : '' ?></div>
    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="act" value="lead_stage"><input type="hidden" name="id" value="<?= $r['id'] ?>"><select name="stage" aria-label="Move <?= e($r['name']) ?> to" data-autosubmit style="padding:5px 8px;width:100%"><?php foreach ($labels as $sk => $sl): ?><option value="<?= $sk ?>" <?= $sk === $k ? 'selected' : '' ?>><?= $sk === $k ? $sl : 'Move to ' . $sl ?></option><?php endforeach; ?></select><noscript><button class="btn sm">Move</button></noscript></form></div><?php endforeach; ?></section><?php endforeach; ?></div>

<div class="grid" style="grid-template-columns:<?= $sel ? '1.2fr 1fr' : '1fr' ?>;align-items:start;margin-top:22px">
<?php if ($sel): ?><div class="card" id="lead"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="margin:0">Lead #<?= (int)$sel['id'] ?>: <?= e($sel['name']) ?></h3><span class="badge"><?= e($labels[$sel['stage']]) ?></span><?php if ($sel['confirmed_at']): ?><span class="badge ok">customer confirmed <?= ago($sel['confirmed_at']) ?></span><?php endif; ?><a class="btn sm" style="margin-left:auto" href="<?= url('app/leads') ?>">Close</a></div>
  <p class="sub" style="margin:6px 0 12px"><span class="mono"><?= e(phone_view($sel['phone'])) ?></span> · created <?= local_dt($sel['created_at']) ?> by <?= $sel['source'] === 'ai' ? 'the AI agent' : 'your team' ?></p>
  <p><b>Interested in:</b> <?= e($sel['interest']) ?><?= $sel['preferred_time'] ? '<br><b>Best time:</b> ' . e($sel['preferred_time']) : '' ?><?= $sel['notes'] ? '<br><b>Notes:</b> ' . e($sel['notes']) : '' ?><?= $sel['lost_reason'] ? '<br><b>Lost because:</b> ' . e($sel['lost_reason']) : '' ?></p>
  <div class="row" style="margin-bottom:12px"><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="act" value="lead_assign"><input type="hidden" name="id" value="<?= $sel['id'] ?>"><label>Owner<select name="assigned_to"><option value="0">Nobody yet</option><?php foreach ($team as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$sel['assigned_to'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></label><button class="btn sm">Assign</button></form>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="act" value="lead_value"><input type="hidden" name="id" value="<?= $sel['id'] ?>"><label>Expected value (Rs.)<input name="value" type="number" min="0" value="<?= e($sel['value']) ?>"></label><button class="btn sm">Save value</button></form></div>
  <form method="post" class="stack" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="lead_stage"><input type="hidden" name="id" value="<?= $sel['id'] ?>"><input type="hidden" name="keep" value="1"><div class="row"><label>Stage<select name="stage"><?php foreach ($labels as $sk => $sl): ?><option value="<?= $sk ?>" <?= $sk === $sel['stage'] ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></label><label>Reason if lost<input name="lost_reason" value="<?= e($sel['lost_reason']) ?>"></label></div><button class="btn sm">Update stage</button></form>
  <?php if ($orders): ?><h3 style="font-size:15px">Orders from this number</h3><?php foreach ($orders as $o): ?><div class="small"><a href="<?= url('app/orders?o=' . $o['order_no']) ?>"><?= e($o['order_no']) ?></a> · <?= e(ucfirst($o['status'])) ?> · Rs. <?= num($o['total']) ?> · <?= ago($o['created_at']) ?></div><?php endforeach; endif; ?>
  <h3 style="font-size:15px;margin-top:14px">Notes</h3>
  <form method="post" style="display:flex;gap:8px"><?= csrf_field() ?><input type="hidden" name="act" value="lead_note"><input type="hidden" name="id" value="<?= $sel['id'] ?>"><input name="body" placeholder="Called, will decide on Friday…" aria-label="New note" style="flex:1"><button class="btn sm">Add</button></form>
  <?php foreach ($notes as $n): ?><div class="small" style="margin-top:8px"><b><?= e($n['who'] ?: 'Team') ?></b> · <span class="muted"><?= ago($n['created_at']) ?></span><br><?= e($n['body']) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="lead_create"><h3>Add a lead</h3>
  <div class="row"><label>Name<input name="name" required></label><label>Phone <small>with country code</small><input name="phone" required placeholder="94771234567"></label></div>
  <label>Interested in<input name="interest" required placeholder="50 polo shirts for a school event"></label>
  <div class="row"><label>Best time to call<input name="preferred_time"></label><label>Expected value (Rs.)<input name="value" type="number" min="0"></label></div>
  <label>Notes<input name="notes"></label><button class="btn primary">Add lead</button></form>
</div>
