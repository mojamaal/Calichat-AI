<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Services\MetaConnect;

$title = 'Channels';
$types = ['whatsapp', 'messenger', 'instagram', 'twilio_whatsapp'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act'); $id = (int)post('id');
    if ($act === 'delete') { Db::q("DELETE FROM channels WHERE id=? AND tenant_id=?", [$id, $tenantId]); Audit::log('channel_delete', 'channel:' . $id); flash('Channel removed. Conversation history is kept.'); }
    elseif ($act === 'toggle') { Db::q("UPDATE channels SET status=IF(status='active','paused','active') WHERE id=? AND tenant_id=?", [$id, $tenantId]); flash('Channel updated.'); }
    elseif ($act === 'meta_finish') { // the owner picked which Pages / Instagram accounts to connect after Facebook Login
        $pend = Db::one("SELECT * FROM meta_pending WHERE id=? AND tenant_id=? AND user_id=? AND expires_at > NOW()", [post('pick'), $tenantId, $u['id']]);
        if (!$pend) { flash('That selection expired. Press Connect again.', 'err'); redirect('app/channels'); }
        $byId = []; foreach (json_decode((string)Crypto::decrypt($pend['payload']), true) ?: [] as $pg) $byId[$pg['id']] = $pg;
        $made = 0; $errs = [];
        foreach ((array)($_POST['sel'] ?? []) as $sel) {
            [$kind, $pid] = array_pad(explode(':', (string)$sel, 2), 2, '');
            $pg = $byId[$pid] ?? null; if (!$pg) continue;
            $sub = MetaConnect::subscribePage($pg['id'], $pg['token']); // idempotent
            if (!$sub['ok']) { $errs[] = $pg['name'] . ': ' . $sub['error']; continue; }
            if ($kind === 'm') { MetaConnect::saveChannel((int)$tenantId, 'messenger', $pg['id'], 'Messenger: ' . $pg['name'], $pg['token']); $made++; }
            if ($kind === 'i' && $pg['ig_id']) { MetaConnect::saveChannel((int)$tenantId, 'instagram', $pg['ig_id'], 'Instagram: @' . ($pg['ig_name'] ?: $pg['ig_id']), $pg['token']); $made++; }
        }
        Db::q("DELETE FROM meta_pending WHERE id=?", [$pend['id']]);
        Audit::log('channel_connect', 'meta', "$made channel(s) via Facebook Login");
        flash($made ? "$made channel(s) connected and subscribed to messages." . ($errs ? ' Some failed: ' . implode('; ', $errs) : '') : 'Nothing was connected. ' . implode('; ', $errs), $made ? 'ok' : 'err');
    }
    else {
        $type = in_array(post('type'), $types, true) ? post('type') : 'whatsapp';
        $agent = (int)post('agent_id') ?: null;
        if ($agent && !Db::val("SELECT id FROM agents WHERE id=? AND tenant_id=?", [$agent, $tenantId])) $agent = null;
        $ext = post('external_id');
        if ($type === 'twilio_whatsapp') $ext = '+' . preg_replace('/\D/', '', preg_replace('/^whatsapp:/i', '', $ext)); // e.g. +14155238886
        $d = ['name' => mb_substr(post('name', ucfirst($type)), 0, 120), 'type' => $type, 'external_id' => mb_substr($ext, 0, 100), 'agent_id' => $agent];
        if ($type === 'twilio_whatsapp') {
            $sid = trim(post('account_sid'));
            if ($sid !== '' && !preg_match('/^AC[0-9a-f]{32}$/i', $sid)) { flash('A Twilio Account SID starts with AC and has 34 characters. Copy it from the Twilio Console home page.', 'err'); redirect('app/channels?type=twilio_whatsapp'); }
            if ($sid !== '') $d['account_sid'] = $sid;
        }
        if (post('access_token') !== '') { $d['access_token'] = Crypto::encrypt(post('access_token')); $d['verified_at'] = null; $d['verify_error'] = null; $d['last_error'] = null; }
        if (post('app_secret') !== '' && $type !== 'twilio_whatsapp') $d['app_secret'] = Crypto::encrypt(post('app_secret'));
        if ($id) { Db::update('channels', $d, 'id=? AND tenant_id=?', [$id, $tenantId]); flash('Channel updated.'); }
        else { $id = Db::insert('channels', $d + ['tenant_id' => $tenantId, 'verify_token' => rand_key(12), 'channel_key' => rand_key(10)]); flash($type === 'twilio_whatsapp' ? 'Channel created. Paste the callback URL into your Twilio WhatsApp Sandbox settings, then press "Verify connection".' : 'Channel created. Paste the callback URL and verify token into Meta, then press "Verify connection".'); }
        Audit::log('channel_save', 'channel:' . $id, $type);
    }
    redirect('app/channels');
}
$channels = Db::all("SELECT * FROM channels WHERE tenant_id=? ORDER BY id", [$tenantId]);
$agents = Db::all("SELECT id, name FROM agents WHERE tenant_id=?", [$tenantId]);
$labels = ['whatsapp' => 'WhatsApp (Meta)', 'messenger' => 'Messenger', 'instagram' => 'Instagram', 'twilio_whatsapp' => 'WhatsApp via Twilio'];
$help = [
    'whatsapp' => ['Phone number ID', 'WhatsApp Cloud API → API Setup → “Phone number ID”. Subscribe the app to the <b>messages</b> webhook field.'],
    'messenger' => ['Facebook Page ID', 'Page access token from your Meta app (Messenger). Subscribe the page to <b>messages</b>.'],
    'instagram' => ['Instagram account ID', 'Instagram-connected Page token with instagram_manage_messages. Subscribe to <b>messages</b>.'],
    'twilio_whatsapp' => ['WhatsApp sender number', 'Twilio Console → Messaging → Try it out → Send a WhatsApp message. For the Sandbox the number is shown there (for example +1 415 523 8886). Account SID and Auth Token are on the Console home page.'],
];
$typeNow = isset($help[$_GET['type'] ?? '']) ? $_GET['type'] : 'whatsapp';
$metaOn = MetaConnect::configured(); $waOn = MetaConnect::whatsappReady(); $owner = Auth::isOwner();
$pickPages = null; $pickId = preg_replace('/[^a-f0-9]/', '', (string)($_GET['pick'] ?? ''));
if ($pickId !== '' && $owner) {
    $pend = Db::one("SELECT payload FROM meta_pending WHERE id=? AND tenant_id=? AND user_id=? AND expires_at > NOW()", [$pickId, $tenantId, $u['id']]);
    $pickPages = $pend ? (json_decode((string)Crypto::decrypt($pend['payload']), true) ?: []) : [];
}
if ($waOn) { // the Facebook SDK needs a wider policy on this page only
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://connect.facebook.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: https://*.facebook.com; connect-src 'self' https://graph.facebook.com https://www.facebook.com https://web.facebook.com; frame-src https://www.facebook.com https://web.facebook.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
?>
<div class="page-head"><div><h1>Messaging <span class="accent">Channels</span></h1><p>Connect WhatsApp, Messenger, Instagram, or a Twilio WhatsApp number. Each channel gets its own secure webhook and can use a different agent.</p></div></div>

<?php if ($pickPages !== null): ?>
<form method="post" class="card stack" style="margin-bottom:22px;border-color:var(--brand)"><?= csrf_field() ?><input type="hidden" name="act" value="meta_finish"><input type="hidden" name="pick" value="<?= e($pickId) ?>">
  <h3>Choose what to connect</h3><p class="sub" style="margin:0">Facebook returned these Pages. Tick the channels you want the AI to answer on.</p>
  <?php if (!$pickPages): ?><div class="empty">This selection expired or had no Pages. <a href="<?= url('meta/connect') ?>">Connect again</a>.</div><?php endif; ?>
  <?php foreach ($pickPages as $pg): ?><div style="border:1px solid var(--line);border-radius:14px;padding:12px 16px"><b><?= e($pg['name']) ?></b>
    <label class="check" style="margin-top:6px"><input type="checkbox" name="sel[]" value="m:<?= e($pg['id']) ?>" checked> Messenger on this Page</label>
    <?php if ($pg['ig_id']): ?><label class="check"><input type="checkbox" name="sel[]" value="i:<?= e($pg['id']) ?>" checked> Instagram @<?= e($pg['ig_name'] ?: $pg['ig_id']) ?></label><?php else: ?><div class="muted small">No Instagram professional account is linked to this Page.</div><?php endif; ?></div><?php endforeach; ?>
  <div class="row" style="justify-content:flex-start"><button class="btn primary" style="flex:none">Connect selected</button><a class="btn" style="flex:none" href="<?= url('app/channels') ?>">Cancel</a></div></form>
<?php endif; ?>

<?php if ($owner): ?>
<div class="card" style="margin-bottom:22px;background:linear-gradient(135deg,var(--card),var(--brand-50))"><h3>Connect your accounts in one click</h3>
  <?php if ($metaOn): ?>
    <p class="sub">Sign in with Facebook and choose your Pages, Instagram and WhatsApp number. CaliChat sets up the webhooks for you. No tokens to copy.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a class="btn primary" href="<?= url('meta/connect') ?>">Connect Facebook Page &amp; Instagram</a>
      <?php if ($waOn): ?><button type="button" class="btn primary" id="wa-signup" data-app-id="<?= e(MetaConnect::appId()) ?>" data-config-id="<?= e(MetaConnect::configId()) ?>" data-version="<?= e(env('META_GRAPH_VERSION', 'v21.0')) ?>">Connect WhatsApp number</button><?php endif; ?>
      <span id="wa-status" class="small muted"></span></div>
    <p class="muted small" style="margin:12px 0 0">You must be an admin of the Facebook Page. Instagram needs a professional (business or creator) account linked to that Page, and “Allow access to messages” turned on in Instagram settings. For WhatsApp you can use a number that is not already on the WhatsApp app.</p>
    <?php if ($waOn): ?><script src="<?= url('assets/js/meta_connect.js') ?>"></script><?php endif; ?>
  <?php else: ?>
    <p class="sub" style="margin:0">One-click connect is not switched on yet<?= Auth::isSuper() ? ' (set up the platform’s Meta app under <a href="' . url('admin/platform') . '">Platform settings</a>)' : ': ask your platform administrator' ?>. You can still connect manually below with your own Meta app tokens or a Twilio number.</p>
  <?php endif; ?></div>
<?php endif; ?>

<div class="grid g2" style="grid-template-columns:1.5fr 1fr">
<div class="stack">
<?php foreach ($channels as $c): $hook = abs_url('webhook/' . $c['channel_key']); $tw = $c['type'] === 'twilio_whatsapp';
  $steps = [['Credentials saved', $tw ? ($c['access_token'] && $c['account_sid'] && $c['external_id']) : ($c['access_token'] && $c['app_secret'] && $c['external_id']), $tw ? 'Add the Account SID, Auth Token and sender number.' : 'Add the ID, access token and app secret.'],
            [$tw ? 'Connection verified with Twilio' : 'Connection verified with Meta', (bool)$c['verified_at'], $c['verify_error'] ?: 'Press “Verify connection”.'],
            ['Webhook receiving messages', (bool)$c['last_webhook_at'], $tw ? 'Paste the callback URL into the Twilio Sandbox “When a message comes in” (method POST), join the sandbox from your phone, then send a message.' : 'Paste the callback URL and verify token into Meta, subscribe to “messages”, then send a test message.']]; ?>
  <div class="card">
    <div class="row" style="align-items:center;flex-wrap:nowrap"><div style="flex:1"><h3><?= e($c['name']) ?> <span class="badge"><?= e($labels[$c['type']] ?? $c['type']) ?></span></h3>
      <div class="muted small">Number/ID: <?= e($c['external_id'] ?: 'not set') ?><?= $c['verified_name'] ? ' · ' . e($c['verified_name']) : '' ?></div></div>
      <span class="badge <?= $c['status'] === 'active' ? 'ok' : 'gray' ?>" style="flex:none"><?= e($c['status']) ?></span></div>
    <ul class="checklist" style="margin:14px 0">
      <?php foreach ($steps as [$l, $ok, $hint]): ?><li><span class="statusdot <?= $ok ? 'ok' : 'warn' ?>" style="margin-top:6px"></span><div><?= e($l) ?><?= $ok ? '' : '<div class="hint">' . e($hint) . '</div>' ?></div></li><?php endforeach; ?>
      <?php if ($c['last_webhook_at']): ?><li class="muted small">Last message received <?= ago($c['last_webhook_at']) ?></li><?php endif; ?>
      <?php if ($c['last_error']): ?><li class="down small">Last send error: <?= e($c['last_error']) ?></li><?php endif; ?>
    </ul>
    <div class="stack" style="gap:10px">
      <div><div class="eyebrow"><?= $tw ? 'Callback URL for Twilio “When a message comes in” (POST, public https)' : 'Callback URL (must be public HTTPS)' ?></div><div class="copybox"><code><?= e($hook) ?></code><button type="button" class="btn sm" data-copy="<?= e($hook) ?>">Copy</button></div></div>
      <?php if (!$tw): ?><div><div class="eyebrow">Verify token</div><div class="copybox"><code><?= e($c['verify_token']) ?></code><button type="button" class="btn sm" data-copy="<?= e($c['verify_token']) ?>">Copy</button></div></div><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap"><button type="button" class="btn sm primary" data-verify="<?= $c['id'] ?>">Verify connection</button><span id="verify-<?= $c['id'] ?>" class="small"></span></div>
    <details class="fold" style="margin-top:14px"><summary>Edit credentials</summary>
      <form method="post" class="stack" style="padding-bottom:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="type" value="<?= e($c['type']) ?>">
        <div class="row"><label>Name<input name="name" value="<?= e($c['name']) ?>"></label><label><?= e($help[$c['type']][0]) ?><input name="external_id" value="<?= e($c['external_id']) ?>"></label></div>
        <?php if ($tw): ?><label>Account SID<input name="account_sid" value="<?= e($c['account_sid']) ?>" placeholder="AC…" autocomplete="off"></label><?php endif; ?>
        <div class="row"><label><?= $tw ? 'Auth Token' : 'Access token' ?> <small>leave blank to keep</small><input name="access_token" type="password" autocomplete="off"></label>
          <?php if (!$tw): ?><label>App secret <small>leave blank to keep</small><input name="app_secret" type="password" autocomplete="off"></label><?php endif; ?></div>
        <label>Agent<select name="agent_id"><option value="">Default (first active)</option><?php foreach ($agents as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$c['agent_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
        <div><button class="btn primary">Save</button></div></form></details>
    <div style="display:flex;gap:8px;margin-top:6px">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm"><?= $c['status'] === 'active' ? 'Pause' : 'Resume' ?></button></form>
      <form method="post" data-confirm="Remove this channel? Conversation history is kept."><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm danger">Remove</button></form></div>
  </div>
<?php endforeach; ?>
<?php if (!$channels): ?><div class="card empty">No channels connected yet.</div><?php endif; ?>
</div>

<form method="post" class="card stack" style="align-self:start"><?= csrf_field() ?>
  <h3>Add a channel</h3>
  <label>Type<select name="type" data-nav-select="?type="><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>" <?= $typeNow === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label>Name<input name="name" required placeholder="<?= $typeNow === 'twilio_whatsapp' ? 'e.g. Twilio WhatsApp Sandbox' : 'e.g. Main store WhatsApp' ?>"></label>
  <label><?= e($help[$typeNow][0]) ?><input name="external_id" required placeholder="<?= $typeNow === 'twilio_whatsapp' ? '+14155238886' : '' ?>"></label>
  <?php if ($typeNow === 'twilio_whatsapp'): ?><label>Account SID<input name="account_sid" required placeholder="AC…" autocomplete="off"></label><?php endif; ?>
  <label><?= $typeNow === 'twilio_whatsapp' ? 'Auth Token <small>also used to verify that webhooks really come from Twilio</small>' : 'Permanent access token' ?><input name="access_token" type="password" autocomplete="off"></label>
  <?php if ($typeNow !== 'twilio_whatsapp'): ?><label>Meta app secret <small>Used to verify webhook signatures</small><input name="app_secret" type="password" autocomplete="off"></label><?php endif; ?>
  <label>Agent<select name="agent_id"><option value="">Default (first active)</option><?php foreach ($agents as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
  <p class="muted small" style="margin:0"><?= $help[$typeNow][1] ?></p>
  <button class="btn primary">Create channel</button>
</form>
</div>
