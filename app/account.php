<?php
declare(strict_types=1);
/** Signed-in password change (also used when a temporary password must be replaced). */

use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$u = Auth::user();
$err = null; $forced = (bool)$u['must_change_password'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = Db::one("SELECT password_hash FROM users WHERE id=?", [$u['id']]);
    $new = (string)($_POST['password'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) $err = 'Your current password is not correct.';
    elseif ($new !== (string)($_POST['password2'] ?? '')) $err = 'The two new passwords do not match.';
    elseif ($p = Auth::passwordProblem($new, $u['email'])) $err = $p;
    else {
        Auth::setPassword((int)$u['id'], $new);
        Audit::log('password_changed', $u['email']);
        flash('Password updated.');
        redirect('');
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change password · <?= e(env('APP_NAME', 'CaliChat')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head><body class="auth-body">
<main class="auth-card">
  <div class="brand-block"><span class="brand-mark">CC</span><span class="brand-name"><?= e(strtoupper(env('APP_NAME', 'CaliChat'))) ?></span></div>
  <h1 style="margin-top:22px"><?= $forced ? 'Set a new' : 'Change' ?> <span class="accent">password</span></h1>
  <p class="muted"><?= $forced ? 'Your account uses a temporary password. Choose your own to continue.' : 'Use at least 12 characters.' ?></p>
  <?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Current password<input type="password" name="current" required autocomplete="current-password" autofocus></label>
    <label>New password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <label>Repeat new password<input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
    <button class="btn primary lg">Update password</button>
    <?php if (!$forced): ?><a class="btn" style="justify-content:center" href="<?= url('') ?>">Cancel</a><?php endif; ?>
  </form>
</main>
</body></html>
