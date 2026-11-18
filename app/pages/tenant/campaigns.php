<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$title = 'Campaigns';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('campaigns_edit');
    $act = post('act');
    if ($act === 'create') {
        $ch = Db::one("SELECT * FROM channels WHERE id=? AND tenant_id=?", [(int)post('channel_id'), $tenantId]);
        if (!$ch || post('name') === '' || post('body') === '') { flash('Channel, name and message are required.', 'err'); redirect('app/campaigns'); }
        $tpl = (int)post('template_id') ? Db::one("SELECT * FROM wa_templates WHERE id=? AND tenant_id=? AND status='approved'", [(int)post('template_id'), $tenantId]) : null;
        $cid = Db::insert('campaigns', ['tenant_id' => $tenantId, 'channel_id' => $ch['id'], 'name' => mb_substr(post('name'), 0, 150), 'template_name' => $tpl ? $tpl['name'] : mb_substr(post('template_name'), 0, 120), 'template_lang' => $tpl ? $tpl['language'] : mb_substr(post('template_lang', 'en'), 0, 10), 'body' => mb_substr(post('body'), 0, 1000), 'status' => 'draft']);
        Audit::log('campaign_create', 'campaign:' . $cid);
        flash('Campaign saved as draft.'); redirect('app/campaigns');
    }
    if ($act === 'launch') {
        $c = Db::one("SELECT * FROM campaigns WHERE id=? AND tenant_id=? AND status='draft'", [(int)post('id'), $tenantId]);
        if ($c) {
            // Only contacts of THIS channel who explicitly opted in.
            $n = Db::q("INSERT INTO campaign_recipients (campaign_id, tenant_id, contact_id) SELECT ?, ?, id FROM contacts WHERE tenant_id=? AND channel_id=? AND consent_status='opted_in'", [$c['id'], $tenantId, $tenantId, $c['channel_id']])->rowCount();
            Db::update('campaigns', ['status' => $n ? 'running' : 'done'], 'id=?', [$c['id']]);
            Audit::log('campaign_launch', 'campaign:' . $c['id'], "$n recipients");
            flash($n ? "Campaign queued for $n opted-in contacts. The worker sends it in the background." : 'No opted-in contacts on this channel yet. Record consent in Contacts first.', $n ? 'ok' : 'err');
        }
        redirect('app/campaigns');
    }
    if ($act === 'delete') {
        Db::q("DELETE FROM campaigns WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); flash('Deleted.'); redirect('app/campaigns');
    }
}
$camps = Db::all("SELECT c.*, ch.name chname, ch.type chtype,
   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id=c.id) total,
   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id=c.id AND r.status='sent') sent,
   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id=c.id AND r.status='failed') failed
   FROM campaigns c JOIN channels ch ON ch.id=c.channel_id WHERE c.tenant_id=? ORDER BY c.id DESC", [$tenantId]);
$channels = Db::all("SELECT ch.id, ch.name, ch.type, (SELECT COUNT(*) FROM contacts k WHERE k.tenant_id=ch.tenant_id AND k.channel_id=ch.id AND k.consent_status='opted_in') audience FROM channels ch WHERE ch.tenant_id=?", [$tenantId]);
?>
<div class="page-head"><div><h1>Proactive <span class="accent">Campaigns</span></h1><p>Send order updates, offers and reminders to customers who agreed to receive them.</p></div></div>
<div class="card" style="margin-bottom:22px;background:var(--warn-bg);border-color:var(--warn)"><b>Meta policy:</b> WhatsApp only allows free-form messages within 24h of the customer's last message. For anything else use an <b>approved template</b> name below. Only contacts you've marked <b>Opted in</b> (Contacts page) on the selected channel are included, and anyone who replies STOP is excluded automatically.</div>
<div class="grid" style="grid-template-columns:1.4fr 1fr;align-items:start">
  <div class="card"><h3>Campaigns</h3><p class="sub">&nbsp;</p>
    <?php if (!$camps): ?><div class="empty">No campaigns yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Name</th><th>Channel</th><th>Progress</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($camps as $c): ?><tr><td><b><?= e($c['name']) ?></b><div class="muted small"><?= e($c['template_name'] ?: 'free text') ?></div></td><td><span class="badge"><?= e($c['chtype']) ?></span></td>
      <td class="small"><?= $c['sent'] ?>/<?= $c['total'] ?> sent<?= $c['failed'] ? ' · <span class="down">' . $c['failed'] . ' failed</span>' : '' ?></td>
      <td><span class="badge <?= $c['status'] === 'done' ? 'ok' : ($c['status'] === 'running' ? 'warn' : 'gray') ?>"><?= e($c['status']) ?></span></td>
      <td style="white-space:nowrap"><?php if ($c['status'] === 'draft'): ?><form method="post" style="display:inline" data-confirm="Send to all opted-in contacts on this channel?"><?= csrf_field() ?><input type="hidden" name="act" value="launch"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm primary">Launch</button></form> <?php endif; ?>
      <form method="post" style="display:inline" data-confirm="Delete campaign?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm danger" aria-label="Delete campaign">×</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="create"><h3>New campaign</h3>
    <label>Name<input name="name" required></label>
    <label>Channel<select name="channel_id"><?php foreach ($channels as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['type']) ?>) — <?= $c['audience'] ?> opted in</option><?php endforeach; ?></select></label>
    <label>Approved WhatsApp template <small>from <a href="<?= url('app/templates') ?>">WA Templates</a>. Campaigns can fill <b>{name}</b> and <b>{shop}</b>; other placeholders are sent as “-”.</small><select name="template_id"><option value="0">None: free text only</option><?php foreach (Db::all("SELECT id, name, language, category FROM wa_templates WHERE tenant_id=? AND status='approved' ORDER BY name", [$tenantId]) as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['language']) ?>, <?= e($t['category']) ?>)</option><?php endforeach; ?></select></label>
    <details><summary class="small muted" style="cursor:pointer">Or type a template name approved elsewhere</summary><div class="row"><label>Template name<input name="template_name" placeholder="order_update"></label><label>Language<input name="template_lang" value="en"></label></div></details>
    <label>Message <small>Free text (Messenger/Instagram) or the first {{1}} template variable. <b>{name}</b> is replaced.</small><textarea name="body" rows="4" required></textarea></label>
    <button class="btn primary">Save draft</button></form>
</div>
