<?php
use CaliChat\Core\Db;

$title = 'Alerts';
$open = Db::all("SELECT * FROM alerts WHERE tenant_id=? AND resolved_at IS NULL ORDER BY severity='crit' DESC, id DESC LIMIT 100", [$tenantId]);
$done = Db::all("SELECT * FROM alerts WHERE tenant_id=? AND resolved_at IS NOT NULL ORDER BY resolved_at DESC LIMIT 15", [$tenantId]);
Db::q("UPDATE alerts SET seen=1 WHERE tenant_id=? AND seen=0", [$tenantId]); // opening the page marks them seen
?>
<div class="page-head"><div><h1>Alerts &amp; <span class="accent">Notifications</span></h1><p>Things that need your attention: handoffs, failing systems, expiring access and usage limits.</p></div>
  <div class="actions"><a class="btn" href="<?= url('app/settings') ?>#notify">Notification settings</a></div></div>
<div class="card"><h3>Open</h3><p class="sub"><?= count($open) ?> item<?= count($open) === 1 ? '' : 's' ?></p>
  <?php if (!$open): ?><div class="empty">All clear.</div><?php endif; ?>
  <?php foreach ($open as $a): ?><div class="alert-item"><span class="sev <?= e($a['severity']) ?>"></span><div style="flex:1"><b><?= e(str_replace('_', ' ', ucfirst($a['type']))) ?></b> <span class="badge gray"><?= e($a['severity']) ?></span><div><?= e($a['message']) ?></div><div class="muted small"><?= ago($a['created_at']) ?></div></div><button class="btn sm" data-resolve="<?= $a['id'] ?>">Resolve</button></div><?php endforeach; ?></div>
<?php if ($done): ?><div class="card" style="margin-top:22px"><h3>Recently resolved</h3><table><tbody><?php foreach ($done as $a): ?><tr><td><?= e($a['message']) ?></td><td class="small muted"><?= ago($a['resolved_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
