<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Automations;
use CaliChat\Services\Templates;

$title = 'WhatsApp Templates';
$owner = Auth::can('templates_edit');
$badge = ['draft' => 'gray', 'pending' => 'warn', 'approved' => 'ok', 'rejected' => 'err', 'paused' => 'warn', 'disabled' => 'err'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('templates_edit');
    $act = post('act'); $id = (int)post('id');
    if ($act === 'tpl_save') {
        $btn = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['buttons'] ?? ''))))), 0, 3);
        $d = ['name' => strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', post('name'))), 'language' => post('language', 'en'), 'category' => post('category') === 'marketing' ? 'marketing' : 'utility',
            'header_text' => mb_substr(post('header_text'), 0, 60) ?: null, 'body' => mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 1024), 'footer' => mb_substr(post('footer'), 0, 60) ?: null,
            'buttons' => $btn ? json_encode($btn, JSON_UNESCAPED_UNICODE) : null, 'channel_id' => Templates::channel($tenantId, (int)post('channel_id') ?: null)['id'] ?? null, 'updated_at' => now()];
        $d['body'] = trim($d['body']);
        $ex = $id ? Db::one("SELECT * FROM wa_templates WHERE id=? AND tenant_id=?", [$id, $tenantId]) : null;
        if ($ex && !in_array($ex['status'], ['draft', 'rejected'], true)) { flash('Meta does not allow editing a template after it was submitted. Create a new version with a new name.', 'err'); redirect('app/templates'); }
        $dup = Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name=? AND language=?", [$tenantId, $d['name'], $d['language']]);
        if ($dup && (int)$dup !== $id) { flash('A template with this name and language already exists.', 'err'); redirect('app/templates'); }
        if ($ex) { Db::update('wa_templates', $d + ['status' => 'draft', 'rejection_reason' => null], 'id=? AND tenant_id=?', [$id, $tenantId]); }
        else { $id = Db::insert('wa_templates', $d + ['tenant_id' => $tenantId]); }
        $p = Templates::problems($d); Audit::log('template_save', 'template:' . $id, $d['name']);
        flash($p['errors'] ? 'Saved as a draft, but it cannot be submitted yet: ' . $p['errors'][0] : 'Saved. Check the preview, then submit it to Meta for approval.', $p['errors'] ? 'err' : 'ok'); redirect('app/templates?t=' . $id . '#editor');
    }
    if ($act === 'tpl_submit') { $r = Templates::submit($tenantId, $id); Audit::log('template_submit', 'template:' . $id, $r['ok'] ? 'ok' : (string)$r['error']); flash($r['ok'] ? 'Submitted to Meta. Approval usually takes minutes to a day. Press Check approval status to refresh.' : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/templates'); }
    if ($act === 'tpl_sync') { $r = Templates::sync($tenantId); flash($r['ok'] ? ($r['updated'] . ' template status(es) changed.') : 'Could not reach Meta: ' . $r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/templates'); }
    if ($act === 'tpl_delete') { Db::q("DELETE FROM wa_templates WHERE id=? AND tenant_id=?", [$id, $tenantId]); Db::q("UPDATE automations SET template_id=NULL WHERE template_id=? AND tenant_id=?", [$id, $tenantId]); Audit::log('template_delete', 'template:' . $id); flash('Template removed here. To remove it from Meta as well, delete it in WhatsApp Manager.'); redirect('app/templates'); }
    if ($act === 'tpl_starters') { $n = Automations::installStarterTemplates($tenantId); flash($n ? "$n starter templates added as drafts. Review each, then submit." : 'Starter templates are already there.'); redirect('app/templates'); }
    if ($act === 'waba_save') {
        $w = preg_replace('/\D/', '', post('waba_id')); Db::update('channels', ['waba_id' => $w ?: null], "id=? AND tenant_id=? AND type='whatsapp'", [(int)post('channel_id'), $tenantId]); flash('Business account saved.'); redirect('app/templates');
    }
}

$tpls = Db::all("SELECT t.*, (SELECT COUNT(*) FROM automations a WHERE a.template_id=t.id AND a.is_active=1) used FROM wa_templates t WHERE t.tenant_id=? ORDER BY t.id DESC", [$tenantId]);
$chs = Db::all("SELECT id, name, waba_id, external_id, managed FROM channels WHERE tenant_id=? AND type='whatsapp' ORDER BY id", [$tenantId]);
$sel = !empty($_GET['t']) ? Db::one("SELECT * FROM wa_templates WHERE id=? AND tenant_id=?", [(int)$_GET['t'], $tenantId]) : null;
$editable = !$sel || in_array($sel['status'], ['draft', 'rejected'], true);
$prob = $sel ? Templates::problems($sel) : null;
$f = $sel ?: ['name' => '', 'language' => 'en', 'category' => 'utility', 'header_text' => '', 'body' => '', 'footer' => '', 'buttons' => null, 'channel_id' => $chs[0]['id'] ?? null];
?>
<div class="page-head"><div><h1>WhatsApp <span class="accent">Templates</span></h1><p>WhatsApp only lets a business start a conversation, or write after 24 hours of silence, with a template Meta has approved. Write them here with readable placeholders; we check Meta's rules before you submit.</p></div>
  <div class="actions"><?php if ($owner): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_sync"><button class="btn">Check approval status</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_starters"><button class="btn">Add starter templates</button></form><?php endif; ?></div></div>

<?php if (!$chs): ?><div class="card" style="margin-bottom:22px;background:var(--warn-bg);border-color:var(--warn)"><b>No Meta WhatsApp channel yet.</b> Templates are submitted through the WhatsApp Cloud API, so connect a number under <a href="<?= url('app/channels') ?>">Channels</a> first. Twilio sandbox numbers cannot use these templates. You can still draft them here.</div>
<?php else: ?><div class="card" style="margin-bottom:22px"><h3>Business account</h3><p class="sub">Meta needs the WhatsApp Business Account ID of the number to submit templates (Meta Business Settings > WhatsApp accounts). One-click connected numbers have it already.</p>
  <?php foreach ($chs as $c): ?><form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="act" value="waba_save"><input type="hidden" name="channel_id" value="<?= $c['id'] ?>">
    <b style="min-width:160px"><?= e($c['name']) ?></b><input name="waba_id" value="<?= e($c['waba_id']) ?>" placeholder="e.g. 1098765432101234" aria-label="WhatsApp Business Account ID for <?= e($c['name']) ?>" style="width:260px" <?= $owner ? '' : 'disabled' ?>><?php if ($owner): ?><button class="btn sm">Save</button><?php endif; ?>
    <?= $c['waba_id'] ? '<span class="badge ok">ready</span>' : '<span class="badge warn">needed to submit</span>' ?></form><?php endforeach; ?></div><?php endif; ?>

<div class="grid" style="grid-template-columns:1.2fr 1fr;align-items:start">
<div class="card"><h3>Your templates</h3><?php if (!$tpls): ?><div class="empty">No templates yet. Add the starter set, or write your own.</div><?php else: ?>
  <div class="tablewrap"><table><thead><tr><th>Template</th><th>Status</th><th>Used by</th><th></th></tr></thead><tbody><?php foreach ($tpls as $t): ?><tr>
    <td><a href="<?= url('app/templates?t=' . $t['id']) ?>#editor"><b class="mono"><?= e($t['name']) ?></b></a><div class="muted small"><?= e($t['language']) ?> · <?= e($t['category']) ?></div><div class="small" style="max-width:360px"><?= e(mb_strimwidth($t['body'], 0, 90, '…')) ?></div></td>
    <td><span class="badge <?= $badge[$t['status']] ?>"><?= e($t['status']) ?></span><?php if ($t['rejection_reason']): ?><div class="small down" style="max-width:220px"><?= e($t['rejection_reason']) ?></div><?php endif; ?><?php if ($t['synced_at']): ?><div class="muted small">checked <?= ago($t['synced_at']) ?></div><?php endif; ?></td>
    <td class="small"><?= $t['used'] ? $t['used'] . ' follow-up(s)' : '—' ?></td>
    <td style="white-space:nowrap"><?php if ($owner): ?><?php if (in_array($t['status'], ['draft', 'rejected'], true)): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_submit"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm primary">Submit to Meta</button></form> <?php endif; ?>
      <form method="post" style="display:inline" data-confirm="Remove this template from CaliChat? It stays in Meta until you delete it in WhatsApp Manager."><?= csrf_field() ?><input type="hidden" name="act" value="tpl_delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm danger" aria-label="Remove template">×</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php if ($owner): ?><form method="post" class="card stack" id="editor"><?= csrf_field() ?><input type="hidden" name="act" value="tpl_save"><input type="hidden" name="id" value="<?= (int)($sel['id'] ?? 0) ?>">
  <h3><?= $sel ? 'Template: ' . e($sel['name']) : 'New template' ?></h3>
  <?php if (!$editable): ?><div class="flash err" style="margin:0">Submitted templates cannot be edited. Change the name (for example <span class="mono"><?= e($f['name']) ?>_v2</span>) and save to make a new one.</div><?php endif; ?>
  <div class="row"><label>Name <small>lowercase_with_underscores</small><input name="name" required value="<?= e($f['name']) ?>" placeholder="order_shipped"></label>
    <label>Language<select name="language"><?php foreach (['en' => 'English (en)', 'si' => 'Sinhala (si)', 'ta' => 'Tamil (ta)', 'en_US' => 'English US (en_US)'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['language'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
  <div class="row"><label>Type<select name="category"><option value="utility" <?= $f['category'] === 'utility' ? 'selected' : '' ?>>Utility: order and account updates</option><option value="marketing" <?= $f['category'] === 'marketing' ? 'selected' : '' ?>>Marketing: offers, reviews, nudges</option></select></label>
    <label>Send from<select name="channel_id"><?php foreach ($chs as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$f['channel_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?><?php if (!$chs): ?><option value="">No WhatsApp channel yet</option><?php endif; ?></select></label></div>
  <label>Header <small>optional, no placeholders</small><input name="header_text" id="tpl-header" maxlength="60" value="<?= e($f['header_text']) ?>"></label>
  <label>Message <small>up to 1,024 characters</small><textarea name="body" id="tpl-body" rows="5" required data-tpl-preview data-samples="<?= e(json_encode(Templates::VARS, JSON_UNESCAPED_UNICODE)) ?>" maxlength="1024"><?= e($f['body']) ?></textarea></label>
  <div style="display:flex;gap:6px;flex-wrap:wrap"><?php foreach (array_keys(Templates::VARS) as $v): ?><button type="button" class="btn sm" data-insert="{<?= $v ?>}">{<?= $v ?>}</button><?php endforeach; ?></div>
  <label>Footer <small>optional, for example “Reply STOP to unsubscribe”</small><input name="footer" id="tpl-footer" maxlength="60" value="<?= e($f['footer']) ?>"></label>
  <label>Quick-reply buttons <small>one per line, up to 3</small><textarea name="buttons" rows="2"><?= e(implode("\n", Templates::buttonList($f['buttons']))) ?></textarea></label>
  <div><div class="muted small" style="margin-bottom:6px">Preview with sample data</div><div id="tpl-preview" class="bubble-preview" aria-live="polite"></div><div class="muted small mono" id="tpl-map" style="margin-top:6px"></div></div>
  <?php if ($prob && ($prob['errors'] || $prob['warnings'])): ?><div><?php foreach ($prob['errors'] as $x): ?><div class="small down">✕ <?= e($x) ?></div><?php endforeach; ?><?php foreach ($prob['warnings'] as $x): ?><div class="small" style="color:var(--warn)">! <?= e($x) ?></div><?php endforeach; ?></div><?php endif; ?>
  <div style="display:flex;gap:10px"><button class="btn primary">Save draft</button><?php if ($sel): ?><a class="btn" href="<?= url('app/templates') ?>">New template</a><?php endif; ?></div></form><?php endif; ?>
</div>
