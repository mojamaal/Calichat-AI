<?php
use CaliChat\Core\Db;

$title = 'Audit log';
$f = trim($_GET['action'] ?? '');
$rows = Db::all("SELECT a.*, u.name uname, t.name tname FROM audit_log a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN tenants t ON t.id=a.tenant_id " . ($f !== '' ? "WHERE a.action=? " : '') . "ORDER BY a.id DESC LIMIT 300", $f !== '' ? [$f] : []);
$actions = array_column(Db::all("SELECT DISTINCT action FROM audit_log ORDER BY action"), 'action');
?>
<div class="page-head"><div><h1>Platform <span class="accent">Audit Log</span></h1><p>Sign-ins, lockouts, tenant changes, impersonation and data exports across the platform. Times are UTC.</p></div>
  <div class="actions"><form method="get"><select name="action" data-autosubmit aria-label="Filter by action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option <?= $f === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></form></div></div>
<div class="card"><div class="tablewrap"><table><thead><tr><th>When (UTC)</th><th>Who</th><th>Tenant</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr><td class="small"><?= e($a['created_at']) ?></td><td><?= e($a['uname'] ?: ($a['user_id'] ? 'user #' . $a['user_id'] : '—')) ?></td><td><?= e($a['tname'] ?: '—') ?></td><td><span class="badge <?= in_array($a['action'], ['login_failed', 'login_locked', 'tenant_delete'], true) ? 'err' : (strpos($a['action'], 'impersonate') === 0 ? 'warn' : '') ?>"><?= e($a['action']) ?></span></td><td class="mono small"><?= e($a['target']) ?></td><td class="small"><?= e($a['detail']) ?></td><td class="mono small"><?= e($a['ip']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
