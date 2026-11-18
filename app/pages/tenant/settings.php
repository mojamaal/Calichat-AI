<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

$title = 'Settings';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act');
    if ($act === 'canned_add') {
        if (post('title') !== '' && post('body') !== '') { Db::insert('canned_replies', ['tenant_id' => $tenantId, 'title' => mb_substr(post('title'), 0, 80), 'body' => mb_substr(post('body'), 0, 2000)]); flash('Saved reply added.'); }
        redirect('app/settings#canned');
    }
    if ($act === 'messaging_save') {
        Tenant::saveSettings((int)$tenantId, ['interactive' => isset($_POST['interactive']) ? 1 : 0, 'voice_replies' => isset($_POST['voice_replies']) ? 1 : 0, 'voice_name' => in_array(post('voice_name'), \CaliChat\Services\Voice::VOICES, true) ? post('voice_name') : 'Kore']);
        Audit::log('settings_messaging', '', 'interactive=' . (int)isset($_POST['interactive']) . ' voice=' . (int)isset($_POST['voice_replies'])); flash('Messaging options saved.'); redirect('app/settings#messaging');
    }
    if ($act === 'canned_del') { Db::q("DELETE FROM canned_replies WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]); flash('Removed.'); redirect('app/settings#canned'); }

    if (post('name') !== '') Db::update('tenants', ['name' => mb_substr(post('name'), 0, 150)], 'id=?', [$tenantId]);
    $tz = post('timezone', 'Asia/Colombo');
    if (in_array($tz, timezone_identifiers_list(), true)) Db::update('tenants', ['timezone' => $tz], 'id=?', [$tenantId]);
    $hook = post('notify_webhook'); $mail = post('notify_email');
    if ($hook !== '' && !preg_match('#^https://#i', $hook)) { flash('The notification webhook must be an https:// URL.', 'err'); redirect('app/settings'); }
    $days = array_values(array_filter(array_map('intval', (array)($_POST['hours_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
    $ret = (int)post('retention_days', '0');
    $patch = [
        'memory_provider' => post('memory_provider') === 'tencentdb' ? 'tencentdb' : 'mysql',
        'memory_enabled' => isset($_POST['memory_enabled']) ? 1 : 0,
        'hours' => $days && preg_match('/^\d\d:\d\d$/', post('hours_start')) && preg_match('/^\d\d:\d\d$/', post('hours_end')) ? ['days' => $days, 'start' => post('hours_start'), 'end' => post('hours_end')] : null,
        'auto_resume_minutes' => max(0, min(1440, (int)post('auto_resume_minutes', '0'))),
        'quota_message' => mb_substr(post('quota_message'), 0, 300),
        'notify_webhook' => $hook, 'notify_email' => filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '',
        'retention_days' => ($ret === 0 || $ret >= 30) ? min($ret, 3650) : 0,
    ];
    if (post('gemini_key') !== '') $patch['gemini_key'] = Crypto::encrypt(post('gemini_key'));
    if (isset($_POST['clear_key'])) $patch['gemini_key'] = null;
    Tenant::saveSettings((int)$tenantId, $patch);
    Audit::log('settings_save', '', 'memory=' . $patch['memory_provider'] . ' retention=' . $patch['retention_days'] . (isset($patch['gemini_key']) ? ' gemini_key_changed' : ''));
    flash('Settings saved.'); redirect('app/settings');
}
$t = Tenant::get((int)$tenantId);
$cfg = $t['cfg'];
$hasOwn = !empty($cfg['gemini_key']);
$mp = $cfg['memory_provider'] ?? env('MEMORY_PROVIDER', 'mysql');
$h = $cfg['hours'] ?? ['days' => [], 'start' => '09:00', 'end' => '18:00'];
$canned = Db::all("SELECT * FROM canned_replies WHERE tenant_id=? ORDER BY title", [$tenantId]);
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$owner = Auth::isOwner();
?>
<div class="page-head"><div><h1>Workspace <span class="accent">Settings</span></h1><p>Business profile, AI credentials, memory, hours, notifications and privacy.</p></div></div>
<?php if (!$owner): ?><div class="card"><p class="muted">Only owners can change settings.</p></div><?php else: ?>
<form method="post" class="card stack" style="max-width:820px"><?= csrf_field() ?>
  <h3>Business</h3>
  <div class="row"><label>Business name<input name="name" value="<?= e($t['name']) ?>"></label>
    <label>Timezone <small>Used for hours, dashboards and message times</small><select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= $t['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label></div>
  <fieldset style="border:1px solid var(--line);border-radius:14px;padding:12px 16px"><legend class="eyebrow">Team hours (the AI tells customers when a human is available)</legend>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px"><?php foreach ($dayNames as $n => $l): ?><label class="check"><input type="checkbox" name="hours_days[]" value="<?= $n ?>" <?= in_array($n, (array)$h['days'], true) ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
    <div class="row"><label>From<input type="time" name="hours_start" value="<?= e($h['start']) ?>"></label><label>To<input type="time" name="hours_end" value="<?= e($h['end']) ?>"></label></div></fieldset>

  <h3 style="margin-top:8px">AI</h3>
  <label>Your own Gemini API key <small><?= $hasOwn ? '✓ A key is saved (encrypted). Enter a new one to replace it.' : 'Optional. If empty, the platform key is used.' ?></small><input name="gemini_key" type="password" autocomplete="off" placeholder="AIza…"></label>
  <?php if ($hasOwn): ?><label class="check"><input type="checkbox" name="clear_key"> Remove my key and use the platform key</label><?php endif; ?>
  <label>Long-term memory engine<select name="memory_provider"><option value="mysql" <?= $mp === 'mysql' ? 'selected' : '' ?>>Built-in (MySQL): customer notes &amp; profile</option><option value="tencentdb" <?= $mp === 'tencentdb' ? 'selected' : '' ?>>TencentDB Agent Memory (sidecar service)</option></select>
    <small>If the TencentDB service is unreachable the system falls back to built-in memory automatically.</small></label>
  <label class="check"><input type="checkbox" name="memory_enabled" <?= ($cfg['memory_enabled'] ?? 1) ? 'checked' : '' ?>> Remember customer preferences between chats (turn off for no long-term memory)</label>
  <label>Message when the monthly AI limit is reached<input name="quota_message" value="<?= e($cfg['quota_message'] ?? '') ?>" placeholder="Thanks for your message! Our team will get back to you shortly."></label>
  <label>Return chats to the AI automatically after (minutes, 0 = never)<input type="number" name="auto_resume_minutes" min="0" max="1440" value="<?= (int)($cfg['auto_resume_minutes'] ?? 0) ?>"></label>

  <h3 id="notify" style="margin-top:8px">Notifications</h3>
  <div class="row"><label>Webhook URL (Slack, Teams, n8n…) <small>https only. Receives handoffs and critical alerts.</small><input name="notify_webhook" value="<?= e($cfg['notify_webhook'] ?? '') ?>" placeholder="https://hooks.slack.com/…"></label>
    <label>Alert e-mail <small>Needs MAIL_ENABLED=1 on the server</small><input type="email" name="notify_email" value="<?= e($cfg['notify_email'] ?? '') ?>"></label></div>

  <h3 style="margin-top:8px">Privacy</h3>
  <label>Delete messages older than (days, 0 = keep forever, minimum 30)<input type="number" name="retention_days" min="0" max="3650" value="<?= (int)($cfg['retention_days'] ?? 0) ?>"></label>
  <div class="muted small">Plan: <b><?= e($t['plan']) ?></b> · <?= num($t['monthly_message_limit']) ?> AI replies and <?= num($t['monthly_token_limit']) ?> tokens per month · Model: <span class="mono"><?= e(env('GEMINI_MODEL', 'gemini-3.1-flash-lite')) ?></span></div>
  <div><button class="btn primary">Save settings</button></div>
</form>

<form method="post" class="card stack" id="messaging" style="max-width:820px;margin-top:22px"><?= csrf_field() ?><input type="hidden" name="act" value="messaging_save"><h3>How the assistant replies on WhatsApp</h3>
  <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="interactive" value="1" <?= (int)($cfg['interactive'] ?? 1) ? 'checked' : '' ?> style="width:auto;margin-top:4px"> <span><b>Tap buttons and lists.</b> Order summaries get Yes / No buttons and product searches get a pick-one list. Meta WhatsApp numbers only; Twilio, Messenger and Instagram get plain text.</span></label>
  <?php $ff = \CaliChat\Services\Voice::ffmpeg(); ?><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="voice_replies" value="1" <?= (int)($cfg['voice_replies'] ?? 0) ? 'checked' : '' ?> style="width:auto;margin-top:4px" <?= $ff ? '' : 'disabled' ?>> <span><b>Answer voice notes with a voice note.</b> The assistant speaks short answers in a natural voice (English, Sinhala, Tamil). Longer answers, links and anything that fails are sent as text. <?= $ff ? '<span class="badge ok">ffmpeg found</span>' : '<span class="badge warn">needs ffmpeg on the server (set FFMPEG_PATH)</span>' ?> Uses extra AI tokens. Check how it sounds in your language before turning it on for customers.</span></label>
  <label>Voice<select name="voice_name"><?php foreach (\CaliChat\Services\Voice::VOICES as $vn): ?><option <?= ($cfg['voice_name'] ?? 'Kore') === $vn ? 'selected' : '' ?>><?= e($vn) ?></option><?php endforeach; ?></select></label><div><button class="btn">Save</button></div></form>
<div class="card" id="canned" style="max-width:820px;margin-top:22px"><h3>Saved replies</h3><p class="sub">Quick answers your team can insert in the inbox.</p>
  <?php foreach ($canned as $c): ?><div class="row" style="align-items:center;flex-wrap:nowrap;padding:8px 0;border-top:1px solid var(--line)"><div style="flex:1"><b><?= e($c['title']) ?></b><div class="muted small"><?= e(mb_substr($c['body'], 0, 120)) ?></div></div>
    <form method="post" style="flex:none;min-width:0"><?= csrf_field() ?><input type="hidden" name="act" value="canned_del"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn sm danger" aria-label="Remove saved reply">×</button></form></div><?php endforeach; ?>
  <form method="post" class="stack" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="act" value="canned_add"><div class="row"><label>Title<input name="title" required maxlength="80"></label><label>Message<input name="body" required maxlength="2000"></label></div><div><button class="btn">Add saved reply</button></div></form></div>
<?php endif; ?>
