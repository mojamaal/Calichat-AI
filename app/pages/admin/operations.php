<?php
use CaliChat\Core\Db;
use CaliChat\Services\Metrics;

$title = 'Operations';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('act') === 'resolve') { Db::q("UPDATE alerts SET resolved_at=NOW(), seen=1 WHERE id=?", [(int)post('id')]); flash('Resolved.'); }
    if (post('act') === 'close_circuit') { Db::q("UPDATE circuits SET open_until=NULL, failures=0 WHERE name=?", [post('name')]); flash('Circuit reset.'); }
    redirect('admin/operations');
}
$p = Metrics::platform();
$w = $p['worker']; $q = $p['queue']; $ai = $p['ai_hour'];
$errPct = $ai['calls'] ? round(100 * $ai['errors'] / $ai['calls'], 1) : 0;
$ser = $p['series']; $lab = fn($d) => date('j', strtotime($d));
$bars = fn($k) => array_combine(array_map($lab, array_keys($ser)), array_column($ser, $k));
$openCirc = array_filter($p['circuits'], fn($c) => $c['open_until'] && strtotime($c['open_until'] . ' UTC') > time());
?>
<div class="page-head"><div><h1>Platform <span class="accent">Operations</span></h1><p>Health, capacity and per-tenant resource usage across the whole platform. Times are UTC.</p></div></div>

<div class="grid g4">
  <div class="card stat"><div class="eyebrow">Queue worker</div><div class="big" style="font-size:26px"><span class="statusdot <?= $w['alive'] ? 'ok' : ($w['inline_fallback'] ? 'warn' : 'bad') ?>"></span><?= $w['alive'] ? 'Running' : ($w['inline_fallback'] ? 'Inline mode' : 'Stopped') ?></div><div class="foot"><?= $w['alive'] ? e($w['info']) . ' · beat ' . $w['age'] . 's ago' : ($w['inline_fallback'] ? 'No daemon: webhooks process a few messages inline. Run start-worker.bat for production.' : 'Start cli/worker.php') ?></div></div>
  <div class="card stat"><div class="eyebrow">Message queue</div><div class="big"><?= $q['pending'] ?></div><div class="foot">oldest <?= $q['oldest_s'] ?>s · <?= round($q['per_min'], 1) ?>/min · <span class="<?= $q['failed'] ? 'down' : '' ?>"><?= $q['failed'] ?> failed</span></div></div>
  <div class="card stat"><div class="eyebrow">AI calls (last hour)</div><div class="big"><?= num($ai['calls']) ?></div><div class="foot"><span class="<?= $errPct > 10 ? 'down' : '' ?>"><?= $errPct ?>% errors</span> · p50 <?= $ai['p50'] !== null ? number_format($ai['p50'] / 1000, 1) . 's' : '—' ?> · p95 <?= $ai['p95'] !== null ? number_format($ai['p95'] / 1000, 1) . 's' : '—' ?></div></div>
  <div class="card stat"><div class="eyebrow">Gemini rate (this minute)</div><div class="big"><?= $p['rate_global'] ?></div><div class="foot">of <?= $p['rate_global_limit'] ?> platform limit · model <span class="mono"><?= e(CaliChat\Services\Gemini::model()) ?></span></div></div>
  <div class="card stat"><div class="eyebrow">Tokens this month</div><div class="big"><?= num($p['month_tokens']) ?></div><div class="foot">est. cost <?= money($p['month_cost']) ?></div></div>
  <div class="card stat"><div class="eyebrow">Database</div><div class="big"><?= bytes_h($p['db_bytes']) ?></div><div class="foot"><?= e($p['big_tables'][0]['t'] ?? '') ?> is the largest table</div></div>
  <div class="card stat"><div class="eyebrow">Logs on disk</div><div class="big"><?= bytes_h($p['log_bytes']) ?></div><div class="foot">disk free <?= bytes_h($p['disk_free']) ?></div></div>
  <div class="card stat"><div class="eyebrow">Open circuit breakers</div><div class="big <?= $openCirc ? 'down' : '' ?>"><?= count($openCirc) ?></div><div class="foot">auto-reset after cool-down</div></div>
</div>

<?php if ($p['alerts']): ?><div class="card" style="margin-top:22px"><h3>Open alerts</h3><p class="sub">Platform and tenant alerts, critical first</p>
  <?php foreach ($p['alerts'] as $a): ?><div class="alert-item"><span class="sev <?= e($a['severity']) ?>"></span><div style="flex:1"><b><?= e($a['tname'] ?: 'Platform') ?></b> · <?= e(str_replace('_', ' ', $a['type'])) ?><div><?= e($a['message']) ?></div><div class="muted small"><?= ago($a['created_at']) ?></div></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resolve"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm">Resolve</button></form></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>AI replies per day</h3><p class="sub">All tenants, last 14 days</p><?= svg_bars($bars('replies')) ?></div>
  <div class="card"><h3>AI errors per day</h3><p class="sub">Failed model calls, all tenants</p><?= svg_bars($bars('errors'), 'var(--err)') ?></div>
</div>

<div class="card" style="margin-top:22px"><h3>Tenant resource usage</h3><p class="sub">This calendar month (UTC) · rows highlighted when a limit is above 80% or errors are elevated</p>
  <div class="tablewrap"><table><thead><tr><th>Tenant</th><th>Status</th><th class="num">Replies</th><th class="num">Tokens</th><th class="num">Est. cost</th><th class="num">AI errors 24h</th><th class="num">Avg AI ms</th><th class="num">Queue</th><th class="num">Messages stored</th><th>Last customer msg</th><th>Alerts</th><th></th></tr></thead><tbody>
  <?php foreach ($p['tenants'] as $t): $rp = $t['lim'] ? $t['replies'] / $t['lim'] : 0; $tp = $t['tlim'] ? ($t['tin'] + $t['tout']) / $t['tlim'] : 0; $hot = $rp > .8 || $tp > .8 || ($t['calls24'] >= 10 && $t['bad24'] / $t['calls24'] > .2); ?>
    <tr <?= $hot ? 'style="background:var(--warn-bg)"' : '' ?>><td><b><?= e($t['name']) ?></b><div class="muted small"><?= e($t['plan']) ?> · <?= e($t['timezone']) ?></div></td><td><span class="badge <?= $t['status'] === 'active' ? 'ok' : 'err' ?>"><?= e($t['status']) ?></span></td>
      <td class="num"><?= num($t['replies']) ?><div class="muted small"><?= round($rp * 100) ?>% of <?= num($t['lim']) ?></div></td><td class="num"><?= num($t['tin'] + $t['tout']) ?><div class="muted small"><?= round($tp * 100) ?>% of <?= num($t['tlim']) ?></div></td><td class="num"><?= money($t['cost']) ?></td>
      <td class="num <?= $t['bad24'] ? 'down' : '' ?>"><?= $t['bad24'] ?>/<?= $t['calls24'] ?></td><td class="num"><?= $t['avg_ms'] ?: '—' ?></td><td class="num <?= $t['failed'] ? 'down' : '' ?>"><?= $t['pending'] ?><?= $t['failed'] ? ' (' . $t['failed'] . ' failed)' : '' ?></td><td class="num"><?= num($t['messages']) ?></td><td class="small"><?= ago($t['last_in']) ?></td>
      <td><?= $t['alerts'] ? '<span class="badge ' . ($t['alerts_crit'] ? 'err' : 'warn') . '">' . $t['alerts'] . '</span>' : '—' ?></td>
      <td><form method="post" action="<?= url('admin/tenants') ?>" style="display:flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="act" value="view"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input name="reason" placeholder="reason" aria-label="Reason" style="width:120px;padding:5px 9px"><button class="btn sm">Open</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Circuit breakers</h3><p class="sub">Automatic protection for Gemini, tenant connectors and memory services</p>
    <?php if (!$p['circuits']): ?><div class="empty">None recorded yet.</div><?php else: ?><table><tbody><?php foreach ($p['circuits'] as $c): $open = $c['open_until'] && strtotime($c['open_until'] . ' UTC') > time(); ?>
      <tr><td class="mono"><?= e($c['name']) ?></td><td><span class="badge <?= $open ? 'err' : 'ok' ?>"><?= $open ? 'open' : 'closed' ?></span></td><td class="small muted"><?= e(mb_substr((string)$c['last_error'], 0, 60)) ?></td><td><?php if ($open): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="close_circuit"><input type="hidden" name="name" value="<?= e($c['name']) ?>"><button class="btn sm">Reset</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="card"><h3>Largest tables</h3><p class="sub">Candidates for retention tuning</p><table><tbody><?php foreach ($p['big_tables'] as $b): ?><tr><td class="mono"><?= e($b['t']) ?></td><td class="num"><?= num($b['r']) ?> rows</td><td class="num"><?= bytes_h($b['b']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>

<div class="card" style="margin-top:22px"><h3>Security &amp; configuration posture</h3><p class="sub">Checks against recommended production settings</p>
  <ul class="checklist"><?php foreach ($p['posture'] as $c): ?><li><span class="statusdot <?= $c['ok'] ? 'ok' : 'warn' ?>" style="margin-top:6px"></span><div><?= e($c['label']) ?><div class="hint"><?= e($c['hint']) ?></div></div></li><?php endforeach; ?></ul></div>
