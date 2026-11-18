<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Platform;
use CaliChat\Services\Gemini;
use CaliChat\Services\MetaConnect;

$title = 'Platform settings';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    Platform::set('meta_app_id', preg_replace('/\D/', '', post('meta_app_id')));
    if (post('meta_app_secret') !== '') Platform::set('meta_app_secret', post('meta_app_secret'));
    Platform::set('meta_wa_config_id', preg_replace('/\D/', '', post('meta_wa_config_id')));
    if (isset($_POST['regen_token'])) Platform::set('meta_verify_token', rand_key(16));
    Audit::log('platform_meta_settings', '', 'app id ' . post('meta_app_id') . (post('meta_app_secret') !== '' ? ' (secret changed)' : ''), null);
    flash('Meta app settings saved.'); redirect('admin/platform');
}
$rows = [
    'Environment' => APP_ENV . (IS_PROD ? ' (hardened)' : ' (development defaults)'),
    'Gemini model' => Gemini::model() . ' · fallback ' . (Gemini::fallbackModel() ?: 'none'),
    'Embedding model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001') . ' · ' . (int)env('EMBED_DIM', 256) . ' dims',
    'Gemini platform key' => env('GEMINI_API_KEY') ? '✓ configured' : '✗ missing (set GEMINI_API_KEY in .env)',
    'Platform rate limit' => (int)env('GEMINI_GLOBAL_RPM', 600) . ' calls/min · timeout ' . (int)env('GEMINI_TIMEOUT', 20) . 's · agent deadline ' . (int)env('AGENT_DEADLINE', 28) . 's',
    'Price assumption (per 1M tokens)' => '$' . env('GEMINI_PRICE_IN_PER_M', '0.10') . ' in / $' . env('GEMINI_PRICE_OUT_PER_M', '0.40') . ' out (edit in .env to match your plan)',
    'Default memory provider' => env('MEMORY_PROVIDER', 'mysql'),
    'Queue processing' => env('QUEUE_INLINE_DRAIN', '1') === '1' ? 'worker daemon, with inline fallback' : 'worker daemon only',
    'Private connector hosts allowed' => ALLOW_PRIVATE_HOSTS ? 'yes (dev) — forced off in production' : 'no',
    'Encryption key id' => \CaliChat\Core\Crypto::currentKid(),
    'Meta Graph version' => env('META_GRAPH_VERSION', 'v21.0'),
    'App URL' => abs_url(''),
    'PHP' => PHP_VERSION . ' / curl ' . (extension_loaded('curl') ? 'ok' : 'MISSING') . ' / openssl ' . (extension_loaded('openssl') ? 'ok' : 'MISSING') . ' / mbstring ' . (extension_loaded('mbstring') ? 'ok' : 'MISSING'),
];
$on = MetaConnect::configured();
?>
<div class="page-head"><div><h1>Platform <span class="accent">Settings</span></h1><p>Server settings come from <span class="mono">.env</span>. The Meta app below turns on one-click connect for every tenant.</p></div></div>

<div class="card" style="max-width:920px;margin-bottom:22px"><h3>Meta app (one-click connect) <span class="badge <?= $on ? 'ok' : 'gray' ?>"><?= $on ? 'enabled' : 'not set up' ?></span></h3>
  <p class="sub">Tenants press “Connect” instead of pasting tokens. You register <b>one</b> Meta app for the whole platform; each tenant authorises it for their own Pages, Instagram and WhatsApp numbers.</p>
  <form method="post" class="stack"><?= csrf_field() ?>
    <div class="row"><label>App ID<input name="meta_app_id" value="<?= e(MetaConnect::appId()) ?>" inputmode="numeric" autocomplete="off"></label>
      <label>App secret <small><?= MetaConnect::appSecret() !== '' ? '✓ saved (encrypted). Enter a new one to replace it.' : 'stored encrypted' ?></small><input name="meta_app_secret" type="password" autocomplete="off"></label>
      <label>WhatsApp Embedded Signup configuration ID <small>optional; enables “Connect WhatsApp number”</small><input name="meta_wa_config_id" value="<?= e(MetaConnect::configId()) ?>" inputmode="numeric" autocomplete="off"></label></div>
    <label class="check"><input type="checkbox" name="regen_token"> Generate a new webhook verify token</label>
    <div><button class="btn primary">Save Meta app</button></div></form>
  <h3 style="margin-top:22px">Paste these into your Meta app</h3>
  <div class="stack" style="gap:10px">
    <div><div class="eyebrow">Webhook callback URL (WhatsApp Business Account, Page and Instagram objects)</div><div class="copybox"><code><?= e(abs_url('webhook/meta')) ?></code><button type="button" class="btn sm" data-copy="<?= e(abs_url('webhook/meta')) ?>">Copy</button></div></div>
    <div><div class="eyebrow">Verify token</div><div class="copybox"><code><?= e(MetaConnect::verifyToken()) ?></code><button type="button" class="btn sm" data-copy="<?= e(MetaConnect::verifyToken()) ?>">Copy</button></div></div>
    <div><div class="eyebrow">Valid OAuth redirect URI (Facebook Login)</div><div class="copybox"><code><?= e(MetaConnect::redirectUri()) ?></code><button type="button" class="btn sm" data-copy="<?= e(MetaConnect::redirectUri()) ?>">Copy</button></div></div>
    <div><div class="eyebrow">Permissions to request</div><div class="copybox"><code><?= e(MetaConnect::SCOPE) ?>, whatsapp_business_management, whatsapp_business_messaging</code></div></div></div>
  <div class="call-box muted small" style="margin-top:14px">Meta requires <b>business verification</b>, a <b>privacy policy URL</b> and <b>App Review</b> (advanced access to the permissions above, and Tech Provider status for Embedded Signup) before customers other than your own team can connect. Until then, connection works only for people with a role on the Meta app. The URLs must be public https.</div></div>

<div class="card" style="max-width:920px"><table><tbody><?php foreach ($rows as $k => $v): ?><tr><td><b><?= e($k) ?></b></td><td class="mono"><?= e($v) ?></td></tr><?php endforeach; ?></tbody></table></div>
