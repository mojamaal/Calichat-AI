<?php
declare(strict_types=1);
/** Public one-time link: invited users set their first password; "reset" links set a new one. */

use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$t = Auth::findToken($token);
$err = null; $done = false;
if ($t && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if ($pw !== (string)($_POST['password2'] ?? '')) $err = 'The two passwords do not match.';
    elseif ($p = Auth::passwordProblem($pw, $t['email'])) $err = $p;
    else {
        Auth::setPassword((int)$t['user_id'], $pw);
        Db::update('password_resets', ['used_at' => now()], 'id=?', [$t['id']]);
        Db::q("DELETE FROM login_attempts WHERE email=?", [$t['email']]);
        Audit::log('password_set', $t['email'], $t['purpose'], null, (int)$t['user_id']);
        flash('Password saved. You can sign in now.');
        redirect('login');
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set password · <?= e(env('APP_NAME', 'CaliChat')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head><body class="auth-body">
<main class="auth-card">
  <div class="brand-block"><span class="brand-mark">CC</span><span class="brand-name"><?= e(strtoupper(env('APP_NAME', 'CaliChat'))) ?></span></div>
  <?php if (!$t): ?>
    <h1 style="margin-top:22px">Link expired</h1><p class="muted">This link is invalid, already used, or has expired. Ask your administrator for a new one.</p><a class="btn" href="<?= url('login') ?>">Back to sign in</a>
  <?php else: ?>
    <h1 style="margin-top:22px"><?= $t['purpose'] === 'invite' ? 'Welcome' : 'Reset' ?> <span class="accent"><?= e(explode(' ', $t['name'])[0]) ?></span></h1>
    <p class="muted">Choose a password for <b><?= e($t['email']) ?></b>. Use at least 12 characters.</p>
    <?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New password<input type="password" name="password" required minlength="12" autocomplete="new-password" autofocus></label>
      <label>Repeat password<input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
      <button class="btn primary lg">Save password</button></form>
  <?php endif; ?>
</main>
</body></html>
