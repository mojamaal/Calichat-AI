<?php
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;
use CaliChat\Services\Metrics;

$title = 'Dashboard';
$t = $tenantRow;
$m = Metrics::tenant((int)$tenantId, 7);
$today = array_values($m['series'])[count($m['series']) - 1];
$pl = $m['plan'];
$convToday = (int)Db::val("SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND is_test=0 AND created_at >= ? AND created_at < ?", array_merge([$tenantId], Tenant::dayRangeUtc($t['timezone'], Tenant::localDate($t['timezone']))));
$contacts = $m['resources']['contacts'];
$recent = Db::all("SELECT c.id, c.status, c.last_preview, c.last_message_at, c.channel_type, ct.name, ct.phone FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.tenant_id=? AND c.is_test=0 ORDER BY c.last_message_at DESC LIMIT 6", [$tenantId]);
$chActive = (int)Db::val("SELECT COUNT(*) FROM channels WHERE tenant_id=? AND status='active'", [$tenantId]);
$toolCount = $m['resources']['tools']; $kbCount = (int)Db::val("SELECT COUNT(*) FROM knowledge_docs WHERE tenant_id=?", [$tenantId]);
$hasKey = Tenant::geminiKey($t) !== '';
$openAlerts = Db::all("SELECT severity, message FROM alerts WHERE tenant_id=? AND resolved_at IS NULL ORDER BY severity='crit' DESC, id DESC LIMIT 3", [$tenantId]);
$serie = array_combine(array_map(fn($d) => date('D', strtotime($d)), array_keys($m['series'])), array_column($m['series'], 'messages_in'));
?>
<div class="page-head">
  <div><h1>Operations <span class="accent">Overview</span></h1><p>Welcome back, <?= e($u['name']) ?>. Here's what your agents are doing today.</p></div>
  <div class="actions"><?php if (\CaliChat\Core\Auth::isOwner()): ?><a class="btn primary" href="<?= url('app/playground') ?>"><?= icon('flask', 18) ?> Test your agent</a><?php endif; ?></div>
</div>

<?php foreach ($openAlerts as $a): ?><div class="flash <?= $a['severity'] === 'crit' ? 'err' : 'ok' ?>" style="<?= $a['severity'] === 'warn' ? 'background:var(--warn-bg);color:var(--warn)' : '' ?>"><?= e($a['message']) ?> <a href="<?= url('app/alerts') ?>">View alerts</a></div><?php endforeach; ?>

<?php $ob = \CaliChat\Services\Onboarding::progress((int)$tenantId); if (!$ob['complete'] && empty($t['cfg']['onboarding_dismissed']) && \CaliChat\Core\Auth::isOwner()): ?>
<div class="card" style="margin-bottom:22px;background:linear-gradient(135deg,var(--card),var(--brand-50))">
  <div class="row" style="align-items:center"><div><h3>Finish setting up (<?= $ob['done'] ?> of <?= $ob['total'] ?>)</h3><div class="gbar" style="margin:8px 0 6px;max-width:420px"><i style="width:<?= (int)round(100 * $ob['done'] / $ob['total']) ?>%"></i></div><div class="muted small">Profile, agent, knowledge, systems, test chat, channels, first real message.<?= !$hasKey ? ' <b>No Gemini key is set yet.</b>' : '' ?></div></div>
    <div style="text-align:right;flex:none"><a class="btn primary" href="<?= url('app/onboarding') ?>">Continue setup</a></div></div>
</div>
<?php endif; ?>

<div class="grid g3">
  <div class="card stat"><div class="eyebrow">Conversations today</div><div class="big"><?= num($convToday) ?></div><div class="foot"><?= num($today['messages_in']) ?> customer messages · <?= num($today['ai_replies']) ?> AI replies</div></div>
  <div class="card stat"><div class="ico <?= $m['open_handoffs'] ? 'warn' : 'ok' ?>"><?= icon($m['open_handoffs'] ? 'alert' : 'check') ?></div><div class="eyebrow">Needs a human</div><div class="big"><?= num($m['open_handoffs']) ?></div><div class="foot"><a href="<?= url('app/inbox?status=human') ?>">Open handoff queue →</a></div></div>
  <div class="card stat"><div class="ico vio"><?= icon('sparkle') ?></div><div class="eyebrow">AI containment (7 days)</div><div class="big"><?= $m['containment'] === null ? '—' : round($m['containment'] * 100) . '%' ?></div><div class="foot"><?= $m['convs'] - $m['convs_handoff'] ?> of <?= $m['convs'] ?> chats handled without a human</div></div>
  <div class="card stat"><div class="eyebrow">AI replies this month</div><div class="big"><?= num($pl['replies']) ?></div><div class="foot">of <?= num($pl['replies_limit']) ?> plan · <span class="<?= $pl['replies_limit'] && $pl['replies'] / $pl['replies_limit'] > .85 ? 'down' : 'up' ?>"><?= pct($pl['replies'], $pl['replies_limit']) ?> used</span></div></div>
  <div class="card stat"><div class="eyebrow">System calls (7 days)</div><div class="big"><?= num($m['sum']['tool_calls']) ?></div><div class="foot"><?php $tc = array_sum(array_column($m['tools'], 'calls')); $to = array_sum(array_column($m['tools'], 'ok')); echo ($tc ? pct($to, $tc) . ' succeeded' : 'none yet') . ' · ' . $toolCount . ' tools live'; ?></div></div>
  <div class="card stat"><div class="eyebrow">Contacts</div><div class="big"><?= num($contacts) ?></div><div class="foot"><?= $chActive ?> active channel<?= $chActive === 1 ? '' : 's' ?> · <a href="<?= url('app/monitoring') ?>">full usage →</a></div></div>
</div>

<div class="grid" style="margin-top:22px;grid-template-columns:1fr 1.4fr">
  <div class="card"><h3>Customer messages</h3><p class="sub">Last 7 days</p><?= svg_bars($serie) ?></div>
  <div class="card"><h3>Recent conversations</h3><p class="sub">Latest activity across all channels</p>
    <?php if (!$recent): ?><div class="empty">No conversations yet.<?= \CaliChat\Core\Auth::isOwner() ? ' Try the <a href="' . url('app/playground') . '">Playground</a>.' : '' ?></div><?php else: ?>
    <div class="tablewrap"><table><tbody><?php foreach ($recent as $r): ?>
      <tr><td><a href="<?= url('app/inbox?c=' . $r['id']) ?>"><b><?= e($r['name'] ?: $r['phone'] ?: 'Customer') ?></b></a><div class="muted small"><?= e(mb_substr((string)$r['last_preview'], 0, 70)) ?></div></td>
      <td><span class="badge gray"><?= e($r['channel_type']) ?></span></td>
      <td><span class="badge <?= $r['status'] === 'human' ? 'warn' : ($r['status'] === 'ai' ? 'ok' : 'gray') ?>"><?= $r['status'] === 'ai' ? 'AI' : e($r['status']) ?></span></td>
      <td class="muted small"><?= ago($r['last_message_at']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
</div>
