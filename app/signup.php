<?php
declare(strict_types=1);
/** Public self sign-up: a business creates its own workspace and starts a free trial. */
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Billing;
use CaliChat\Services\Notifier;

if (Auth::user()) redirect('');
$open = Billing::signupOpen(); $plans = Billing::plans(); $err = null; $v = ['business' => '', 'owner' => '', 'email' => '', 'plan' => $plans[0]['code'] ?? 'starter'];

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['business' => post('business'), 'owner' => post('owner'), 'email' => strtolower(post('email')), 'plan' => post('plan')];
    $pw = (string)($_POST['password'] ?? ''); $ip = client_ip();
    if (post('website') !== '') { usleep(400000); redirect('login'); } // honeypot: bots fill the hidden field
    elseif ((int)Db::val("SELECT COUNT(*) FROM audit_log WHERE action='signup' AND ip=? AND created_at > NOW() - INTERVAL 1 HOUR", [$ip]) >= 5) $err = 'Too many sign-ups from this address. Please try again later.';
    elseif (mb_strlen($v['business']) < 2 || mb_strlen($v['owner']) < 2) $err = 'Enter your business name and your name.';
    elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
    elseif (Db::val("SELECT id FROM users WHERE email=?", [$v['email']])) $err = 'That email already has an account. Sign in instead.';
    elseif (($pp = Auth::passwordProblem($pw, $v['email'])) !== null) $err = $pp;
    elseif (!Billing::plan($v['plan']) || !array_filter($plans, fn($p) => $p['code'] === $v['plan'])) $err = 'Choose a plan.';
    else {
        $slug = slugify($v['business']); if (Db::val("SELECT id FROM tenants WHERE slug=?", [$slug])) $slug .= '-' . rand_key(2);
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            $tid = Db::insert('tenants', ['name' => mb_substr($v['business'], 0, 150), 'slug' => $slug, 'plan' => $v['plan'], 'timezone' => 'Asia/Colombo', 'settings' => '{}', 'signup_source' => 'self']);
            Billing::startTrial($tid, $v['plan']);
            Db::insert('users', ['tenant_id' => $tid, 'name' => mb_substr($v['owner'], 0, 120), 'email' => $v['email'], 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'owner']);
            Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Assistant', 'business_name' => $v['business'], 'instructions' => 'Help customers of ' . $v['business'] . ' with product questions, orders and general enquiries. Be friendly and brief.']);
            $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        Audit::log('signup', 'tenant:' . $tid, $v['business'] . ' / ' . $v['plan'], $tid, 0);
        Notifier::alert(null, 'info', 'new_signup', "New sign-up: {$v['business']} ({$v['plan']}, trial).", 'signup:' . $tid, 1);
        if (Auth::attempt($v['email'], $pw) === 'ok') redirect('');
        redirect('login');
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create your account · <?= e(env('APP_NAME', 'CaliChat')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head><body class="auth-body">
<main class="auth-card" style="max-width:560px">
  <div class="brand-block"><span class="brand-mark">CC</span><span class="brand-name"><?= e(strtoupper(env('APP_NAME', 'CaliChat'))) ?></span></div>
  <div class="brand-tag">AGENTIC CONVERSATIONS</div>
  <h1>Start your <span class="accent">free trial</span></h1>
  <p class="muted">An AI assistant for your WhatsApp, Messenger and Instagram. <?= Billing::trialDays() ?> days free, no card needed.</p>
  <?php if (!$open): ?><div class="flash err">New sign-ups are closed right now. Please contact us.</div><p><a href="<?= url('login') ?>">Sign in</a></p>
  <?php else: ?>
  <?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Business name<input name="business" required value="<?= e($v['business']) ?>" autocomplete="organization"></label>
    <label>Your name<input name="owner" required value="<?= e($v['owner']) ?>" autocomplete="name"></label>
    <label>Email<input type="email" name="email" required value="<?= e($v['email']) ?>" autocomplete="username"></label>
    <label>Password <small>at least 12 characters</small><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 6px">Choose a plan (change it any time)</legend>
      <?php foreach ($plans as $p): ?><label style="display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:8px"><input type="radio" name="plan" value="<?= e($p['code']) ?>" <?= $v['plan'] === $p['code'] ? 'checked' : '' ?> style="width:auto;margin-top:4px"><span><b><?= e($p['name']) ?></b> · Rs. <?= num($p['price_lkr']) ?> a month<br><span class="muted small"><?= e($p['description']) ?></span></span></label><?php endforeach; ?></fieldset>
    <button class="btn primary lg" type="submit">Create my account</button>
  </form>
  <p class="muted small" style="margin-top:14px">Already have an account? <a href="<?= url('login') ?>">Sign in</a>. Pay by bank transfer when your trial ends; no card is stored.</p><?php endif; ?>
</main>
</body></html>
