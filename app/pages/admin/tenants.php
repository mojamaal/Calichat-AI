<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

$title = 'Tenants';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    if ($act === 'stop_view') { Auth::stopImpersonation(); redirect('admin/tenants'); }
    if ($act === 'view') {
        $tid = (int)post('id'); $reason = post('reason');
        if (!Db::val("SELECT id FROM tenants WHERE id=?", [$tid])) { flash('Tenant not found.', 'err'); redirect('admin/tenants'); }
        if (mb_strlen($reason) < 8) { flash('Enter a reason (at least 8 characters) before opening a tenant workspace. It is recorded in the tenant\'s audit log.', 'err'); redirect('admin/tenants'); }
        Auth::startImpersonation($tid, $reason); redirect('app/dashboard');
    }
    if ($act === 'create') {
        $email = strtolower(post('email'));
        if (post('name') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Business name and a valid owner email are required.', 'err'); redirect('admin/tenants?new=1'); }
        if (Db::val("SELECT id FROM users WHERE email=?", [$email])) { flash('Email already in use.', 'err'); redirect('admin/tenants?new=1'); }
        $tz = post('timezone', 'Asia/Colombo'); if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Colombo';
        $slug = slugify(post('name')); if (Db::val("SELECT id FROM tenants WHERE slug=?", [$slug])) $slug .= '-' . rand_key(2);
        $tid = Db::insert('tenants', ['name' => mb_substr(post('name'), 0, 150), 'slug' => $slug, 'plan' => mb_substr(post('plan', 'starter'), 0, 40), 'monthly_message_limit' => max(0, (int)post('limit', '2000')),
            'monthly_token_limit' => max(0, (int)post('tokens', '5000000')), 'llm_rpm' => max(1, (int)post('rpm', '120')), 'timezone' => $tz, 'settings' => '{}']);
        $uid = Db::insert('users', ['tenant_id' => $tid, 'name' => mb_substr(post('owner_name', 'Owner'), 0, 120), 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'role' => 'owner', 'must_change_password' => 1]);
        Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Assistant', 'business_name' => post('name'), 'instructions' => 'Help customers with product questions, orders and general enquiries for ' . post('name') . '.']);
        $_SESSION['link'] = ['for' => $email, 'url' => abs_url('reset?token=' . Auth::createToken($uid, 'invite', 72)), 'hours' => 72];
        Audit::log('tenant_create', 'tenant:' . $tid, post('name'), $tid); flash('Tenant created. Send the owner the one-time link below.');
    } elseif ($act === 'status') {
        Db::q("UPDATE tenants SET status=IF(status='active','suspended','active') WHERE id=?", [(int)post('id')]);
        Audit::log('tenant_status', 'tenant:' . (int)post('id'), '', (int)post('id')); flash('Tenant updated. Suspended tenants are signed out immediately and stop receiving AI replies.');
    } elseif ($act === 'limits') {
        Db::update('tenants', ['monthly_message_limit' => max(0, (int)post('limit')), 'monthly_token_limit' => max(0, (int)post('tokens')), 'llm_rpm' => max(1, (int)post('rpm')), 'plan' => mb_substr(post('plan', 'starter'), 0, 40)], 'id=?', [(int)post('id')]);
        Audit::log('tenant_limits', 'tenant:' . (int)post('id'), json_encode(['m' => (int)post('limit'), 't' => (int)post('tokens'), 'rpm' => (int)post('rpm')]), (int)post('id')); flash('Plan updated.');
    } elseif ($act === 'delete') {
        $t = Db::one("SELECT id, name FROM tenants WHERE id=?", [(int)post('id')]);
        if ($t && post('confirm') === $t['name']) {
            Audit::log('tenant_delete', 'tenant:' . $t['id'], $t['name'], null);
            Db::q("DELETE FROM tenants WHERE id=?", [$t['id']]); // every tenant table cascades
            flash('Tenant and all of its data were permanently deleted.');
        } else flash('Type the exact business name to confirm deletion.', 'err');
    } elseif ($act === 'reset_link') {
        $o = Db::one("SELECT id, email FROM users WHERE tenant_id=? AND role='owner' ORDER BY id LIMIT 1", [(int)post('id')]);
        if ($o) { $_SESSION['link'] = ['for' => $o['email'], 'url' => abs_url('reset?token=' . Auth::createToken((int)$o['id'], 'reset', 2)), 'hours' => 2]; Audit::log('password_reset_link', $o['email'], '', (int)post('id')); flash('Reset link created for the owner.'); }
    }
    redirect('admin/tenants');
}
$link = $_SESSION['link'] ?? null; unset($_SESSION['link']);
$mStart = gmdate('Y-m-01');
$rows = Db::all("SELECT t.*, (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) users, (SELECT COUNT(*) FROM channels c WHERE c.tenant_id=t.id) channels,
  (SELECT COALESCE(SUM(ai_replies),0) FROM usage_daily d WHERE d.tenant_id=t.id AND d.day >= ?) replies,
  (SELECT COALESCE(SUM(tokens_in+tokens_out),0) FROM usage_daily d WHERE d.tenant_id=t.id AND d.day >= ?) tokens FROM tenants t ORDER BY t.id DESC", [$mStart, $mStart]);
?>
<div class="page-head"><div><h1>Manage <span class="accent">Tenants</span></h1><p>Each tenant is a fully isolated business workspace. Opening one requires a reason and is recorded in their audit log.</p></div></div>
<?php if ($link): ?><div class="card" style="margin-bottom:22px;border-color:var(--brand)"><h3>One-time link for <?= e($link['for']) ?></h3><p class="sub">Valid for <?= (int)$link['hours'] ?> hours and works once. It is shown only now.</p><div class="copybox"><code><?= e($link['url']) ?></code><button type="button" class="btn sm" data-copy="<?= e($link['url']) ?>">Copy</button></div></div><?php endif; ?>
<div class="grid" style="grid-template-columns:1.7fr 1fr;align-items:start">
  <div class="stack">
  <?php foreach ($rows as $t): ?>
    <div class="card">
      <div class="row" style="align-items:center;flex-wrap:nowrap"><div style="flex:1"><h3><?= e($t['name']) ?> <span class="badge <?= $t['status'] === 'active' ? 'ok' : 'err' ?>"><?= e($t['status']) ?></span></h3>
        <div class="muted small"><?= $t['users'] ?> users · <?= $t['channels'] ?> channels · <?= e($t['timezone']) ?> · this month: <?= num($t['replies']) ?> replies, <?= num($t['tokens']) ?> tokens</div></div></div>
      <details class="fold" style="margin-top:12px"><summary>Plan &amp; limits</summary>
        <form method="post" class="stack" style="padding-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="limits"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <div class="row"><label>Plan<input name="plan" value="<?= e($t['plan']) ?>"></label><label>AI replies / month<input type="number" name="limit" value="<?= $t['monthly_message_limit'] ?>"></label><label>Tokens / month<input type="number" name="tokens" value="<?= $t['monthly_token_limit'] ?>"></label><label>AI calls / minute<input type="number" name="rpm" value="<?= $t['llm_rpm'] ?>"></label></div>
          <div><button class="btn sm primary">Save limits</button></div></form></details>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="act" value="view"><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <input name="reason" placeholder="Reason for opening this workspace (recorded)" aria-label="Reason" style="flex:1;min-width:220px;padding:8px 12px"><button class="btn sm">Open workspace</button></form>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm <?= $t['status'] === 'active' ? 'danger' : '' ?>"><?= $t['status'] === 'active' ? 'Suspend' : 'Activate' ?></button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="reset_link"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm">Owner reset link</button></form>
        <details><summary class="btn sm danger" style="list-style:none;cursor:pointer">Delete tenant…</summary>
          <form method="post" class="stack" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><label>Type <b><?= e($t['name']) ?></b> to permanently delete all data<input name="confirm" autocomplete="off"></label><button class="btn sm danger">Delete forever</button></form></details></div>
    </div>
  <?php endforeach; ?>
  </div>
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="create"><h3>New tenant</h3>
    <label>Business name<input name="name" required></label>
    <div class="row"><label>Plan<input name="plan" value="starter"></label><label>AI replies / month<input type="number" name="limit" value="2000"></label></div>
    <div class="row"><label>Tokens / month<input type="number" name="tokens" value="5000000"></label><label>AI calls / minute<input type="number" name="rpm" value="120"></label></div>
    <label>Timezone<select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= $z === 'Asia/Colombo' ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
    <label>Owner name<input name="owner_name" required></label><label>Owner email<input type="email" name="email" required></label>
    <button class="btn primary">Create tenant</button><p class="muted small" style="margin:0">The owner receives a one-time link to set their own password.</p></form>
</div>
