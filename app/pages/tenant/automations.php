<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;
use CaliChat\Core\Crypto;
use CaliChat\Services\Automations;
use CaliChat\Services\Sms;
use CaliChat\Services\Templates;

$title = 'Follow-ups';
$owner = Auth::can('automations_edit');
$T = Automations::TRIGGERS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('automations_edit');
    $act = post('act'); $id = (int)post('id');
    if ($act === 'auto_save') {
        $trig = isset($T[post('trigger_type')]) ? post('trigger_type') : '';
        if ($trig === '' || post('name') === '') { flash('Name and trigger are required.', 'err'); redirect('app/automations'); }
        $cond = []; if (in_array(post('source'), ['ai', 'manual'], true)) $cond['source'] = post('source'); if ((float)post('min_total') > 0) $cond['min_total'] = (float)post('min_total');
        $tpl = (int)post('template_id'); if ($tpl && !Db::val("SELECT id FROM wa_templates WHERE id=? AND tenant_id=?", [$tpl, $tenantId])) $tpl = 0;
        $body = trim((string)($_POST['body'] ?? ''));
        if ($trig !== 'lead_stale' && $body === '' && !$tpl) { flash('Write the message, attach an approved template, or both.', 'err'); redirect('app/automations'); }
        $d = ['name' => mb_substr(post('name'), 0, 120), 'trigger_type' => $trig, 'delay_minutes' => max(0, min(60 * 24 * 30, (int)post('delay_minutes'))), 'body' => $body !== '' ? mb_substr($body, 0, 1000) : null, 'template_id' => $tpl ?: null, 'conditions' => $cond ? json_encode($cond) : null, 'sms_fallback' => isset($_POST['sms_fallback']) ? 1 : 0, 'is_active' => isset($_POST['is_active']) ? 1 : 0];
        if ($id && Db::val("SELECT id FROM automations WHERE id=? AND tenant_id=?", [$id, $tenantId])) Db::update('automations', $d, 'id=? AND tenant_id=?', [$id, $tenantId]); else $id = Db::insert('automations', $d + ['tenant_id' => $tenantId]);
        Audit::log('automation_save', 'automation:' . $id, $d['name']); flash('Follow-up saved.'); redirect('app/automations');
    }
    if ($act === 'auto_toggle') { Db::q("UPDATE automations SET is_active = 1 - is_active WHERE id=? AND tenant_id=?", [$id, $tenantId]); Audit::log('automation_toggle', 'automation:' . $id); redirect('app/automations'); }
    if ($act === 'auto_delete') { Db::q("DELETE FROM automations WHERE id=? AND tenant_id=?", [$id, $tenantId]); Audit::log('automation_delete', 'automation:' . $id); flash('Follow-up deleted.'); redirect('app/automations'); }
    if ($act === 'auto_recommended') { $n = Automations::installRecommended($tenantId); flash($n ? "$n recommended follow-ups added." : 'The recommended follow-ups are already there.'); redirect('app/automations'); }
    if ($act === 'quiet_save') {
        $s = post('quiet_start', '21:00'); $e = post('quiet_end', '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) { flash('Use times like 21:00.', 'err'); redirect('app/automations'); }
        Tenant::saveSettings($tenantId, ['quiet_start' => $s, 'quiet_end' => $e]); Audit::log('automation_quiet_hours', '', "$s-$e"); flash('Quiet hours saved.'); redirect('app/automations');
    }
    if ($act === 'sms_save') {
        $url = trim(post('sms_url')); if ($url !== '' && !Sms::urlOk($url)) { flash('The SMS address must start with https://', 'err'); redirect('app/automations'); }
        $patch = ['sms_url' => $url, 'sms_method' => post('sms_method') === 'GET' ? 'GET' : 'POST', 'sms_body' => mb_substr((string)($_POST['sms_body'] ?? ''), 0, 600), 'sms_header' => preg_replace('/[^A-Za-z0-9\-]/', '', post('sms_header'))];
        if (post('sms_secret') !== '') $patch['sms_secret'] = Crypto::encrypt(post('sms_secret'));
        Tenant::saveSettings($tenantId, $patch); Audit::log('sms_settings', '', $url); flash($url === '' ? 'SMS fallback switched off.' : 'SMS settings saved. Send a test message to check them.'); redirect('app/automations');
    }
    if ($act === 'sms_test') { $r = Sms::send($tenantId, post('to'), 'Test message from ' . ($tenantRow['name'] ?? 'CaliChat') . '. If you can read this, SMS works.'); flash($r['ok'] ? 'Test SMS sent.' : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/automations'); }
    if ($act === 'run_now') { $sc = Automations::scan(); $r = Automations::tick(); flash("Checked now: $sc new item(s) scheduled; {$r['sent']} sent, {$r['skipped']} skipped, {$r['failed']} failed, {$r['later']} waiting for quiet hours to end."); redirect('app/automations'); }
}

$cfg = Tenant::get($tenantId)['cfg'] ?? [];
$list = Db::all("SELECT a.*, t.name tpl_name, t.status tpl_status,
   (SELECT COUNT(*) FROM automation_runs r WHERE r.automation_id=a.id AND r.status='sent' AND r.created_at > NOW() - INTERVAL 30 DAY) sent30,
   (SELECT COUNT(*) FROM automation_runs r WHERE r.automation_id=a.id AND r.status='scheduled') waiting
   FROM automations a LEFT JOIN wa_templates t ON t.id=a.template_id WHERE a.tenant_id=? ORDER BY a.id", [$tenantId]);
$runs = Db::all("SELECT r.*, a.name aname, a.trigger_type FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE r.tenant_id=? ORDER BY r.id DESC LIMIT 40", [$tenantId]);
$sum = array_column(Db::all("SELECT status, COUNT(*) n FROM automation_runs WHERE tenant_id=? AND created_at > NOW() - INTERVAL 7 DAY GROUP BY status", [$tenantId]), 'n', 'status');
$tpls = Db::all("SELECT id, name, status, language FROM wa_templates WHERE tenant_id=? ORDER BY (status='approved') DESC, name", [$tenantId]);
$sel = !empty($_GET['a']) ? Db::one("SELECT * FROM automations WHERE id=? AND tenant_id=?", [(int)$_GET['a'], $tenantId]) : null;
$sc = $sel ? (json_decode((string)$sel['conditions'], true) ?: []) : [];
$f = $sel ?: ['name' => '', 'trigger_type' => 'order_shipped', 'delay_minutes' => 0, 'body' => '', 'template_id' => null, 'sms_fallback' => 0, 'is_active' => 1];
$smsCfg = Sms::config($tenantId);
$rb = ['sent' => 'ok', 'skipped' => 'gray', 'failed' => 'err', 'scheduled' => 'warn'];
$hasWorker = \CaliChat\Services\Queue::workerAlive();
?>
<div class="page-head"><div><h1>Customer <span class="accent">Follow-ups</span></h1><p>Messages that go out on their own: order updates, payment reminders, review requests and nudges for chats that went quiet. Every message and every skipped message is logged below with the reason.</p></div>
  <div class="actions"><?php if ($owner): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="run_now"><button class="btn">Check now</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="auto_recommended"><button class="btn primary">Add recommended set</button></form><?php endif; ?></div></div>

<?php if (!$hasWorker): ?><div class="card" style="margin-bottom:22px;background:var(--warn-bg);border-color:var(--warn)"><b>The background worker is not running.</b> Follow-ups are sent by the worker (<span class="mono">start-worker.bat</span>) or the one-minute cron job. Until then, press “Check now”.</div><?php endif; ?>
<div class="card" style="margin-bottom:22px;background:var(--brand-50)"><b>How the rules work:</b> Free text is sent only within 24 hours of the customer's last message (WhatsApp rule). After that an <b>approved template</b> is needed. Review requests and “chat went quiet” nudges are promotional, so they go only to customers marked <b>Opted in</b>. Nothing is sent during quiet hours.</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:22px">
  <?php foreach (['sent' => 'Sent', 'skipped' => 'Skipped', 'failed' => 'Failed', 'scheduled' => 'Waiting'] as $k => $l): ?><div class="card stat"><div class="muted small"><?= $l ?> · last 7 days</div><div class="big"><?= (int)($sum[$k] ?? 0) ?></div></div><?php endforeach; ?></div>

<div class="grid" style="grid-template-columns:1.4fr 1fr;align-items:start">
<div class="card"><h3>Your follow-ups</h3><?php if (!$list): ?><div class="empty">None yet. “Add recommended set” creates the usual ones for a shop, ready to switch on or edit.</div><?php else: ?>
  <div class="tablewrap"><table><thead><tr><th>Follow-up</th><th>When</th><th>Message</th><th class="num">Sent (30d)</th><th></th></tr></thead><tbody><?php foreach ($list as $a): $c = json_decode((string)$a['conditions'], true) ?: []; ?><tr style="<?= $a['is_active'] ? '' : 'opacity:.55' ?>">
    <td><a href="<?= url('app/automations?a=' . $a['id']) ?>#form"><b><?= e($a['name']) ?></b></a><div class="muted small"><?= e($T[$a['trigger_type']][0] ?? $a['trigger_type']) ?><?= !empty($c['source']) ? ' · ' . ($c['source'] === 'manual' ? 'staff-placed orders only' : 'AI-placed orders only') : '' ?></div></td>
    <td class="small"><?= (int)$a['delay_minutes'] === 0 ? 'Right away' : ((int)$a['delay_minutes'] % 60 === 0 && $a['delay_minutes'] >= 60 ? ($a['delay_minutes'] / 60) . ' h after' : $a['delay_minutes'] . ' min after') ?></td>
    <td class="small"><?php if ($a['trigger_type'] === 'lead_stale'): ?>Alerts your team<?php else: ?><?= $a['body'] ? 'Text' : '' ?><?= $a['body'] && $a['template_id'] ? ' + ' : '' ?><?php if ($a['template_id']): ?>Template <span class="mono"><?= e($a['tpl_name']) ?></span> <span class="badge <?= $a['tpl_status'] === 'approved' ? 'ok' : 'warn' ?>"><?= e($a['tpl_status']) ?></span><?php endif; ?><?php endif; ?></td>
    <td class="num"><?= (int)$a['sent30'] ?><?= $a['waiting'] ? '<div class="muted small">' . (int)$a['waiting'] . ' waiting</div>' : '' ?></td>
    <td style="white-space:nowrap"><?php if ($owner): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="auto_toggle"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm <?= $a['is_active'] ? '' : 'primary' ?>"><?= $a['is_active'] ? 'Switch off' : 'Switch on' ?></button></form>
      <form method="post" style="display:inline" data-confirm="Delete this follow-up?"><?= csrf_field() ?><input type="hidden" name="act" value="auto_delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm danger" aria-label="Delete follow-up">×</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php if ($owner): ?><div class="stack">
<form method="post" class="card stack" id="form"><?= csrf_field() ?><input type="hidden" name="act" value="auto_save"><input type="hidden" name="id" value="<?= (int)($sel['id'] ?? 0) ?>"><h3><?= $sel ? 'Edit follow-up' : 'New follow-up' ?></h3>
  <label>Name<input name="name" required value="<?= e($f['name']) ?>"></label>
  <label>When<select name="trigger_type"><?php foreach ($T as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['trigger_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <div class="muted small" style="margin-top:-8px"><?php foreach ($T as $k => [$l, $kind, $help]): ?><div><b><?= e($l) ?>:</b> <?= e($help) ?></div><?php endforeach; ?></div>
  <label>Wait (minutes) <small>0 = right away, 1440 = one day</small><input name="delay_minutes" type="number" min="0" value="<?= (int)$f['delay_minutes'] ?>"></label>
  <label>Message <small>free text, used within 24 hours of the customer's last message. Placeholders: <?= e(implode(' ', array_map(fn($k) => '{' . $k . '}', array_keys(Templates::VARS)))) ?></small><textarea name="body" rows="4"><?= e($f['body']) ?></textarea></label>
  <label>Template for after 24 hours <small>(must be approved to be used)</small><select name="template_id"><option value="0">None</option><?php foreach ($tpls as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$f['template_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= e($t['language']) ?>, <?= e($t['status']) ?>)</option><?php endforeach; ?></select></label>
  <div class="row"><label>Only orders placed by<select name="source"><option value="">Anyone</option><option value="manual" <?= ($sc['source'] ?? '') === 'manual' ? 'selected' : '' ?>>Your team</option><option value="ai" <?= ($sc['source'] ?? '') === 'ai' ? 'selected' : '' ?>>The AI agent</option></select></label><label>Order total at least (Rs.)<input name="min_total" type="number" min="0" value="<?= e($sc['min_total'] ?? '') ?>"></label></div>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="sms_fallback" value="1" <?= $f['sms_fallback'] ? 'checked' : '' ?> style="width:auto"> Send by SMS when WhatsApp cannot reach the customer <?= $smsCfg['url'] === '' ? '<span class="muted small">(set up SMS below first)</span>' : '' ?></label>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?> style="width:auto"> Switched on</label>
  <div style="display:flex;gap:10px"><button class="btn primary">Save follow-up</button><?php if ($sel): ?><a class="btn" href="<?= url('app/automations') ?>">New</a><?php endif; ?></div></form>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="sms_save"><h3>SMS fallback</h3><p class="sub" style="margin:0">Use your SMS provider's web address (Dialog, Mobitel, Text.lk, Notify.lk and others offer one). Write <b>{to}</b> and <b>{message}</b> where the number and text go.</p>
  <label>Provider address<input name="sms_url" value="<?= e($smsCfg['url']) ?>" placeholder="https://sms.example.lk/api/send"></label>
  <div class="row"><label>Method<select name="sms_method"><option <?= $smsCfg['method'] === 'POST' ? 'selected' : '' ?>>POST</option><option <?= $smsCfg['method'] === 'GET' ? 'selected' : '' ?>>GET</option></select></label><label>Body <small>POST only; JSON or form</small><input name="sms_body" value="<?= e($smsCfg['body']) ?>" placeholder='{"to":"{to}","message":"{message}"}'></label></div>
  <div class="row"><label>Key header name<input name="sms_header" value="<?= e($smsCfg['header']) ?>" placeholder="Authorization"></label><label>Key value <small><?= $smsCfg['secret'] !== '' ? 'saved; leave blank to keep' : 'optional' ?></small><input name="sms_secret" type="password" autocomplete="off"></label></div><button class="btn">Save SMS settings</button></form>
<?php if ($smsCfg['url'] !== ''): ?><form method="post" class="card" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="act" value="sms_test"><label style="flex:1;min-width:180px">Send a test SMS to<input name="to" placeholder="94771234567" required></label><button class="btn">Send test</button></form><?php endif; ?>
<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="quiet_save"><h3>Quiet hours</h3><p class="sub" style="margin:0">No follow-ups are sent during these hours (<?= e(get_tz()) ?>). They go out when quiet hours end.</p>
  <div class="row"><label>From<input name="quiet_start" type="time" value="<?= e($cfg['quiet_start'] ?? '21:00') ?>"></label><label>Until<input name="quiet_end" type="time" value="<?= e($cfg['quiet_end'] ?? '08:00') ?>"></label></div><button class="btn">Save</button></form></div><?php endif; ?>
</div>

<div class="card" style="margin-top:22px"><h3>What happened</h3><p class="sub">The latest 40 follow-ups, including the ones that were not sent and why.</p>
<?php if (!$runs): ?><div class="empty">Nothing has run yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Follow-up</th><th>For</th><th>Due</th><th>Result</th><th>Why</th></tr></thead><tbody><?php foreach ($runs as $r): ?><tr><td><?= e($r['aname']) ?></td><td class="small mono"><?= e($r['phone'] ?: ($r['subject_type'] . ' #' . $r['subject_id'])) ?></td><td class="muted small"><?= local_dt($r['due_at']) ?></td><td><span class="badge <?= $rb[$r['status']] ?>"><?= e($r['status']) ?></span></td><td class="small"><?= e($r['reason'] ?: '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
