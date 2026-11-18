<?php
use CaliChat\Core\Auth;
use CaliChat\Core\Db;

Auth::requireOwner();
$title = 'Audit log';
$rows = Db::all("SELECT a.*, u.name uname, u.email FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.tenant_id=? ORDER BY a.id DESC LIMIT 200", [$tenantId]);
?>
<div class="page-head"><div><h1>Audit <span class="accent">Log</span></h1><p>Who did what in this workspace: sign-ins, settings, tools, data exports and platform-admin access.</p></div></div>
<div class="card"><?php if (!$rows): ?><div class="empty">Nothing recorded yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr><td class="small"><?= local_dt($a['created_at'], 'M j, H:i') ?></td><td><?= e($a['uname'] ?: ($a['user_id'] ? 'user #' . $a['user_id'] : 'system')) ?></td><td><span class="badge"><?= e($a['action']) ?></span></td><td class="mono"><?= e($a['target']) ?></td><td class="small"><?= e($a['detail']) ?></td><td class="mono small"><?= e($a['ip']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
