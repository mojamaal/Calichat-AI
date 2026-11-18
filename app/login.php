<?php
declare(strict_types=1);

use CaliChat\Core\Auth;

if (Auth::user()) redirect('');
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $r = Auth::attempt(post('email'), (string)($_POST['password'] ?? ''));
    if ($r === 'ok') redirect('');
    if ($r === 'locked') $err = 'Too many failed attempts. Please wait 15 minutes and try again.';
    else { $err = 'Invalid email or password.'; usleep(300000); }
}
$msg = flash();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · <?= e(env('APP_NAME', 'CaliChat')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head><body class="auth-body">
<main class="auth-card">
  <div class="brand-block"><span class="brand-mark">CC</span><span class="brand-name"><?= e(strtoupper(env('APP_NAME', 'CaliChat'))) ?></span></div>
  <div class="brand-tag">AGENTIC CONVERSATIONS</div>
  <h1>Welcome <span class="accent">back</span></h1>
  <p class="muted">Sign in to manage your AI agents, channels and conversations.</p>
  <?php if ($msg): ?><div class="flash <?= e($msg[1]) ?>"><?= e($msg[0]) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required autofocus autocomplete="username" value="<?= e(post('email')) ?>"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary lg" type="submit">Sign in</button>
  </form>
  <?php if (\CaliChat\Services\Billing::signupOpen()): ?><p class="muted small" style="margin-top:14px">New here? <a href="<?= url('signup') ?>">Start a free trial</a></p><?php endif; ?>
</main>
</body></html>
