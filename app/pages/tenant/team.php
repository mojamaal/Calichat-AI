<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Perms;

$title = 'Team';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireOwner();
    $act = post('act');
    if ($act === 'invite') {
        $email = strtolower(post('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || post('name') === '') flash('A name and a valid email are required.', 'err');
        elseif (Db::val("SELECT id FROM users WHERE email=?", [$email])) flash('That email is already in use.', 'err');
        elseif (($pl = \CaliChat\Services\Billing::plan((string)(\CaliChat\Core\Tenant::get($tenantId)['plan'] ?? ''))) && (int)Db::val("SELECT COUNT(*) FROM users WHERE tenant_id=? AND status='active'", [$tenantId]) >= (int)$pl['max_users']) flash('Your ' . $pl['name'] . ' plan allows ' . (int)$pl['max_users'] . ' team members. Upgrade under Plan & billing to add more.', 'err');
        else {
            // The new member sets their own password through a one-time link; nobody ever handles a temporary password.
            $uid = Db::insert('users', ['tenant_id' => $tenantId, 'name' => mb_substr(post('name'), 0, 120), 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'role' => post('role') === 'owner' ? 'owner' : 'agent', 'must_change_password' => 1]);
            $_SESSION['link'] = ['for' => $email, 'url' => abs_url('reset?token=' . Auth::createToken($uid, 'invite', 72)), 'hours' => 72];
            Audit::log('user_invite', $email, post('role')); flash('Invitation created. Send the link below to the new member.');
        }
    } elseif ($act === 'reset') {
        $u2 = Db::one("SELECT id, email FROM users WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]);
        if ($u2) { $_SESSION['link'] = ['for' => $u2['email'], 'url' => abs_url('reset?token=' . Auth::createToken((int)$u2['id'], 'reset', 2)), 'hours' => 2]; Audit::log('password_reset_link', $u2['email']); flash('Reset link created.'); }
    } elseif ($act === 'caps' || $act === 'caps_reset') {
        $m = Db::one("SELECT id, role, email FROM users WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]);
        if (!$m || $m['role'] !== 'agent') flash('Owners always have full access.', 'err');
        else {
            $caps = $act === 'caps_reset' ? null : json_encode(array_values(array_intersect(array_map('strval', (array)($_POST['caps'] ?? [])), Perms::all())));
            Db::update('users', ['permissions' => $caps], 'id=? AND tenant_id=?', [$m['id'], $tenantId]);
            Audit::log('user_permissions', $m['email'], $caps ?? 'default'); flash($act === 'caps_reset' ? 'Access reset to the standard set.' : 'Access saved.');
        }
    } elseif ($act === 'toggle' && (int)post('id') !== (int)$u['id']) {
        Db::q("UPDATE users SET status=IF(status='active','disabled','active') WHERE id=? AND tenant_id=?", [(int)post('id'), $tenantId]);
        Audit::log('user_toggle', 'user:' . (int)post('id')); flash('Updated.');
    }
    redirect('app/team');
}
$link = $_SESSION['link'] ?? null; unset($_SESSION['link']);
$users = Db::all("SELECT * FROM users WHERE tenant_id=? ORDER BY id", [$tenantId]);
$owner = Auth::isOwner();
?>
<div class="page-head"><div><h1>Your <span class="accent">Team</span></h1><p>Owners manage everything. Agents can use the inbox and view data. New members set their own password through a one-time link.</p></div></div>
<?php if ($link): ?><div class="card" style="margin-bottom:22px;border-color:var(--brand)"><h3>One-time link for <?= e($link['for']) ?></h3><p class="sub">Valid for <?= (int)$link['hours'] ?> hours and works once. It is shown only now.</p><div class="copybox"><code><?= e($link['url']) ?></code><button type="button" class="btn sm" data-copy="<?= e($link['url']) ?>">Copy</button></div></div><?php endif; ?>
<div class="grid" style="grid-template-columns:1.4fr 1fr;align-items:start">
  <div class="card"><div class="tablewrap"><table><thead><tr><th>Name</th><th>Role</th><th>Last login</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $m): ?><tr><td><b><?= e($m['name']) ?></b><div class="muted small"><?= e($m['email']) ?></div></td><td><span class="badge"><?= e($m['role']) ?></span> <?= $m['status'] !== 'active' ? '<span class="badge gray">disabled</span>' : '' ?></td><td class="muted small"><?= ago($m['last_login']) ?></td>
    <td style="white-space:nowrap"><?php if ($owner): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn sm">Reset link</button></form>
      <?php if ((int)$m['id'] !== (int)$u['id']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn sm <?= $m['status'] === 'active' ? '' : 'primary' ?>"><?= $m['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form><?php endif; endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <?php if ($owner): ?><form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="invite"><h3>Invite a member</h3>
    <label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label>
    <label>Role<select name="role"><option value="agent">Agent (inbox)</option><option value="owner">Owner (full access)</option></select></label><button class="btn primary">Create invitation</button></form><?php endif; ?>
</div>
<?php if ($owner): $groups = []; foreach (Perms::CAPS as $k => [$lbl, $grp]) $groups[$grp][$k] = $lbl; ?>
<div class="card" style="margin-top:22px"><h3>Who can do what</h3><p class="sub">Owners can do everything. For each team member, tick what they may see and change. Pages they may not open disappear from their menu. “Standard access” is the starting set for new members.</p>
<?php foreach ($users as $m): if ($m['role'] !== 'agent') continue; $have = Perms::forUser($m); ?>
<details style="border-top:1px solid var(--line);padding:12px 0"><summary style="cursor:pointer;font-weight:700"><?= e($m['name']) ?> <span class="muted small" style="font-weight:400"><?= e($m['email']) ?> · <?= $m['permissions'] === null ? 'standard access' : 'custom access (' . count($have) . ' of ' . count(Perms::all()) . ')' ?></span></summary>
<form method="post" class="stack" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="act" value="caps"><input type="hidden" name="id" value="<?= $m['id'] ?>">
<?php foreach ($groups as $g => $list): ?><fieldset style="border:0;padding:0;margin:0"><legend class="small muted" style="padding:0 0 4px"><?= e($g) ?></legend><?php foreach ($list as $k => $lbl): ?><label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:3px"><input type="checkbox" name="caps[]" value="<?= e($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?> style="width:auto;margin-top:3px"> <span><?= e($lbl) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?>
<div style="display:flex;gap:8px"><button class="btn primary">Save access</button><button class="btn" name="act" value="caps_reset">Back to standard</button><input type="hidden" name="_x" value="1"></div></form></details><?php endforeach; ?>
<?php if (!array_filter($users, fn($m) => $m['role'] === 'agent')): ?><div class="muted small">No team members besides owners yet. Invite one above.</div><?php endif; ?></div><?php endif; ?>
