<?php
use CaliChat\Core\Db;
use CaliChat\Services\Metrics;

$title = 'Platform';
$p = Metrics::platform();
$s = Db::one("SELECT (SELECT COUNT(*) FROM tenants) tenants, (SELECT COUNT(*) FROM tenants WHERE status='active') active, (SELECT COUNT(*) FROM channels) channels, (SELECT COUNT(*) FROM conversations WHERE is_test=0) convs, (SELECT COUNT(*) FROM contacts WHERE channel_type<>'playground') contacts");
$top = array_slice($p['tenants'], 0, 8);
$crit = (int)Db::val("SELECT COUNT(*) FROM alerts WHERE resolved_at IS NULL AND severity='crit'");
?>
<div class="page-head"><div><h1>Platform <span class="accent">Overview</span></h1><p>Health and usage across every tenant.</p></div><div class="actions"><a class="btn" href="<?= url('admin/operations') ?>"><?= icon('activity', 18) ?> Operations</a><a class="btn primary" href="<?= url('admin/tenants') ?>"><?= icon('plus', 18) ?> Tenants</a></div></div>
<?php if ($crit): ?><div class="flash err"><?= $crit ?> critical alert<?= $crit === 1 ? '' : 's' ?> open. <a href="<?= url('admin/operations') ?>">Review</a></div><?php endif; ?>
<div class="grid g3">
  <div class="card stat"><div class="eyebrow">Tenants</div><div class="big"><?= $s['tenants'] ?></div><div class="foot"><?= $s['active'] ?> active</div></div>
  <div class="card stat"><div class="eyebrow">AI replies today (UTC)</div><div class="big"><?= num(end($p['series'])['replies']) ?></div><?= svg_spark(array_column($p['series'], 'replies')) ?><div class="foot"><?= num($p['month_tokens']) ?> tokens this month · est. <?= money($p['month_cost']) ?></div></div>
  <div class="card stat"><div class="eyebrow">Channels / Contacts</div><div class="big"><?= $s['channels'] ?> / <?= num($s['contacts']) ?></div><div class="foot"><?= num($s['convs']) ?> conversations</div></div>
  <div class="card stat"><div class="eyebrow">Queue</div><div class="big"><?= $p['queue']['pending'] ?></div><div class="foot">worker <?= $p['worker']['alive'] ? 'running' : ($p['worker']['inline_fallback'] ? 'inline mode' : 'stopped') ?> · <?= $p['queue']['failed'] ?> failed</div></div>
  <div class="card stat"><div class="eyebrow">AI errors (last hour)</div><div class="big"><?= $p['ai_hour']['errors'] ?></div><div class="foot">of <?= $p['ai_hour']['calls'] ?> calls</div></div>
  <div class="card stat"><div class="eyebrow">Database</div><div class="big"><?= bytes_h($p['db_bytes']) ?></div><div class="foot">logs <?= bytes_h($p['log_bytes']) ?></div></div>
</div>
<div class="card" style="margin-top:22px"><h3>Top tenants this month</h3><p class="sub">By AI replies</p>
<div class="tablewrap"><table><thead><tr><th>Tenant</th><th>Plan</th><th class="num">Replies</th><th class="num">Tokens</th><th class="num">Est. cost</th></tr></thead><tbody><?php foreach ($top as $t): ?>
<tr><td><b><?= e($t['name']) ?></b></td><td><span class="badge"><?= e($t['plan']) ?></span></td><td class="num"><?= num($t['replies']) ?> / <?= num($t['lim']) ?></td><td class="num"><?= num($t['tin'] + $t['tout']) ?></td><td class="num"><?= money($t['cost']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
