<?php
use CaliChat\Core\Auth;
use CaliChat\Services\Metrics;

$title = 'Usage & Monitoring';
$days = in_array((int)($_GET['d'] ?? 14), [7, 14, 30, 90], true) ? (int)$_GET['d'] : 14;
$m = Metrics::tenant((int)$tenantId, $days);
$s = $m['sum']; $pl = $m['plan']; $q = $m['queue']; $ai = $m['ai']; $r = $m['resources'];
$lab = fn($d) => date('j', strtotime($d)); // day-of-month axis labels
$serie = fn($k) => array_combine(array_map($lab, array_keys($m['series'])), array_column($m['series'], $k));
$tokens = array_combine(array_map($lab, array_keys($m['series'])), array_map(fn($x) => $x['tokens_in'] + $x['tokens_out'], $m['series']));
$errPct = round($ai['error_rate'] * 100, 1);
$health = ($q['oldest_s'] > 300 || $q['failed'] > 0 || $errPct > 20) ? 'bad' : (($q['oldest_s'] > 60 || $errPct > 5) ? 'warn' : 'ok');
?>
<div class="page-head"><div><h1>Usage &amp; <span class="accent">Monitoring</span></h1><p>Resource usage, AI performance, system calls and delivery health for <?= e($tenantRow['name']) ?>. Times are in <?= e(get_tz()) ?>.</p></div>
  <div class="actions"><form method="get"><select name="d" data-autosubmit aria-label="Time range"><?php foreach ([7, 14, 30, 90] as $o): ?><option value="<?= $o ?>" <?= $days === $o ? 'selected' : '' ?>>Last <?= $o ?> days</option><?php endforeach; ?></select></form></div></div>

<div class="card" style="margin-bottom:22px"><div class="row" style="align-items:center">
  <div><span class="statusdot <?= $health ?>"></span><b><?= $health === 'ok' ? 'All systems normal' : ($health === 'warn' ? 'Minor degradation' : 'Needs attention') ?></b>
    <div class="muted small">Queue wait <?= $q['oldest_s'] ?>s · AI error rate <?= $errPct ?>% · <?= $q['failed'] ?> failed message<?= $q['failed'] == 1 ? '' : 's' ?> · <?= $m['open_handoffs'] ?> waiting for a human</div></div>
  <div style="text-align:right"><a class="btn sm" href="<?= url('app/alerts') ?>">View alerts</a></div></div></div>

<div class="grid g4">
  <div class="card stat"><div class="eyebrow">AI replies</div><div class="big"><?= num($s['ai_replies']) ?></div><?= svg_spark(array_column($m['series'], 'ai_replies')) ?><div class="foot"><?= num($s['messages_in']) ?> customer messages in</div></div>
  <div class="card stat"><div class="eyebrow">Tokens used</div><div class="big"><?= num($s['tokens_in'] + $s['tokens_out']) ?></div><?= svg_spark(array_values($tokens)) ?><div class="foot"><?= num($s['tokens_in']) ?> in · <?= num($s['tokens_out']) ?> out</div></div>
  <div class="card stat"><div class="eyebrow">Estimated AI cost</div><div class="big"><?= money($m['cost']) ?></div><div class="foot">+ <?= money($m['test_cost']) ?> from playground tests · list prices set in .env</div></div>
  <div class="card stat"><div class="eyebrow">AI response time</div><div class="big"><?= $ai['p50'] !== null ? number_format($ai['p50'] / 1000, 1) . 's' : '—' ?></div><div class="foot">median · p95 <?= $ai['p95'] !== null ? number_format($ai['p95'] / 1000, 1) . 's' : '—' ?> per model call</div></div>
  <div class="card stat"><div class="eyebrow">Containment (AI only)</div><div class="big"><?= $m['containment'] === null ? '—' : round($m['containment'] * 100) . '%' ?></div><div class="foot"><?= $m['convs'] - $m['convs_handoff'] ?> of <?= $m['convs'] ?> chats resolved without a human</div></div>
  <div class="card stat"><div class="eyebrow">System calls</div><div class="big"><?= num($s['tool_calls']) ?></div><div class="foot"><?php $tc = array_sum(array_column($m['tools'], 'calls')); $to = array_sum(array_column($m['tools'], 'ok')); echo $tc ? round(100 * $to / $tc) . '% succeeded' : 'no calls yet'; ?></div></div>
  <div class="card stat"><div class="eyebrow">Handoffs</div><div class="big"><?= num($s['handoffs']) ?></div><div class="foot"><?= $m['handoff_wait_min'] !== null ? 'avg wait ' . $m['handoff_wait_min'] . ' min to resolve' : 'none resolved yet' ?></div></div>
  <div class="card stat"><div class="eyebrow">Voice &amp; images read</div><div class="big"><?= num($s['media_in']) ?></div><div class="foot"><?= num($m['new_contacts']) ?> new contacts</div></div>
</div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Plan usage this month</h3><p class="sub">Limits reset on the 1st (<?= e(get_tz()) ?>)</p>
    <?= gauge($pl['replies_limit'] ? 100 * $pl['replies'] / $pl['replies_limit'] : 0, 'AI replies', num($pl['replies']) . ' of ' . num($pl['replies_limit'])) ?>
    <?= gauge($pl['tokens_limit'] ? 100 * $pl['tokens'] / $pl['tokens_limit'] : 0, 'AI token budget', num($pl['tokens']) . ' of ' . num($pl['tokens_limit'])) ?>
    <?= gauge($pl['rpm_limit'] ? 100 * $pl['rpm_now'] / $pl['rpm_limit'] : 0, 'AI calls this minute', $pl['rpm_now'] . ' of ' . $pl['rpm_limit'] . ' per minute') ?>
    <div class="kv" style="margin-top:6px"><span>Cost this month (est.)</span><span><?= money($pl['month_cost']) ?></span></div></div>
  <div class="card"><h3>Queue &amp; delivery</h3><p class="sub">Inbound messages from your channels</p>
    <div class="kv"><span>Waiting / in progress</span><span><?= $q['pending'] ?></span><span>Oldest waiting</span><span><?= $q['oldest_s'] ?>s</span><span>Average time to start processing</span><span><?= $q['avg_lag_s'] !== null ? $q['avg_lag_s'] . 's' : '—' ?></span><span>Processed in range</span><span><?= num($q['processed']) ?></span><span>Failed (dead-letter)</span><span class="<?= $q['failed'] ? 'down' : '' ?>"><?= $q['failed'] ?></span></div></div>
</div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Customer messages per day</h3><p class="sub"><?= e($m['range']['from']) ?> to <?= e($m['range']['to']) ?></p><?= svg_bars($serie('messages_in')) ?></div>
  <div class="card"><h3>Tokens per day</h3><p class="sub">Input + output, real chats only</p><?= svg_bars($tokens, 'var(--violet)') ?></div>
</div>
<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Busiest hours</h3><p class="sub">Customer messages by hour of day</p><?= svg_bars(array_combine(array_map(fn($h) => (string)$h, array_keys($m['hours'])), $m['hours']), 'var(--brand)', 110) ?></div>
  <div class="card"><h3>Top contacts</h3><p class="sub">By messages sent in range</p>
    <?php if (!$m['top_contacts']): ?><div class="empty">No customer messages yet.</div><?php else: ?><table><tbody><?php foreach ($m['top_contacts'] as $c): ?><tr><td><b><?= e($c['name'] ?: ($c['phone'] ?: $c['external_id'])) ?></b></td><td><span class="badge gray"><?= e($c['channel_type']) ?></span></td><td class="num"><?= $c['n'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
</div>

<div class="card" style="margin-top:22px"><h3>AI models</h3><p class="sub">Calls by model and purpose<?= $m['truncated'] ? ' · <span class="down">' . $m['truncated'] . ' replies hit the output limit</span>' : '' ?></p>
  <?php if (!$m['by_model']): ?><div class="empty">No AI calls in this range.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Model</th><th>Purpose</th><th class="num">Calls</th><th class="num">Errors</th><th class="num">Avg time</th><th class="num">Tokens in</th><th class="num">Tokens out</th><th class="num">Est. cost</th></tr></thead><tbody>
  <?php foreach ($m['by_model'] as $r2): ?><tr><td class="mono"><?= e($r2['model']) ?></td><td><span class="badge"><?= e($r2['kind']) ?></span></td><td class="num"><?= num($r2['calls']) ?></td><td class="num <?= $r2['errors'] ? 'down' : '' ?>"><?= $r2['errors'] ?></td><td class="num"><?= num($r2['avg_ms']) ?> ms</td><td class="num"><?= num($r2['tin']) ?></td><td class="num"><?= num($r2['tout']) ?></td><td class="num"><?= money(Metrics::cost((int)$r2['tin'], (int)$r2['tout'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<div class="card" style="margin-top:22px"><h3>System calls by tool</h3><p class="sub">Your ERP, CRM and other connected systems</p>
  <?php if (!$m['tools']): ?><div class="empty">No tool calls in this range.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Tool</th><th class="num">Calls</th><th class="num">Success</th><th class="num">Avg time</th><th class="num">Slowest</th><th class="num">From cache</th></tr></thead><tbody>
  <?php foreach ($m['tools'] as $t): ?><tr><td class="mono"><?= e($t['tool']) ?></td><td class="num"><?= num($t['calls']) ?></td><td class="num <?= $t['ok'] < $t['calls'] ? 'down' : 'up' ?>"><?= pct($t['ok'], $t['calls']) ?></td><td class="num"><?= num($t['avg_ms']) ?> ms</td><td class="num"><?= num($t['max_ms']) ?> ms</td><td class="num"><?= num($t['cached']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Channel health</h3><p class="sub">Last message received from Meta and connection status</p>
    <?php if (!$m['channels']): ?><div class="empty">No channels yet.</div><?php endif; ?>
    <?php foreach ($m['channels'] as $c): $stale = $c['last_webhook_at'] ? (time() - strtotime($c['last_webhook_at'] . ' UTC')) : null; $tw = $c['type'] === 'twilio_whatsapp'; $bad = $c['last_error'] || $c['verify_error'] || !$c['has_token'] || (!$tw && !$c['has_secret']); ?>
      <div class="alert-item"><span class="statusdot <?= $bad ? 'bad' : ($c['last_webhook_at'] ? 'ok' : 'warn') ?>" style="margin-top:7px"></span><div><b><?= e($c['name']) ?></b> <span class="badge gray"><?= e($c['type']) ?></span>
        <div class="muted small"><?= $c['last_webhook_at'] ? 'Last message ' . ago($c['last_webhook_at']) : 'No messages received yet. Finish the Meta webhook setup.' ?><?= !$c['has_token'] ? ' · token missing' : '' ?><?= (!$tw && !$c['has_secret']) ? ' · app secret missing' : '' ?></div>
        <?php if ($c['last_error']): ?><div class="down small">Last send error: <?= e($c['last_error']) ?></div><?php endif; ?></div></div>
    <?php endforeach; ?></div>
  <div class="card"><h3>Stored data</h3><p class="sub">What this workspace currently holds</p>
    <div class="kv"><span>Messages</span><span><?= num($r['messages']) ?> (<?= bytes_h($r['message_bytes']) ?> of text)</span><span>Contacts</span><span><?= num($r['contacts']) ?></span><span>Customer memories</span><span><?= num($r['memories']) ?></span><span>Knowledge chunks</span><span><?= num($r['chunks']) ?> (<?= num($r['chunks_embedded']) ?> semantic)</span><span>Tool call logs</span><span><?= num($r['tool_logs']) ?></span><span>AI call logs</span><span><?= num($r['ai_calls']) ?></span><span>Channels / tools</span><span><?= $r['channels'] ?> / <?= $r['tools'] ?></span></div></div>
</div>

<div class="grid g2" style="margin-top:22px">
  <div class="card"><h3>Recent AI errors</h3><p class="sub">Real chats only</p>
    <?php if (!$m['ai_errors']): ?><div class="empty">No AI errors. 🎉</div><?php else: ?><div class="tablewrap"><table><tbody><?php foreach ($m['ai_errors'] as $e2): ?><tr><td class="small muted"><?= local_dt($e2['created_at'], 'M j H:i') ?></td><td class="mono"><?= e($e2['model']) ?></td><td class="small"><?= e($e2['http_status']) ?> <?= e(mb_substr((string)$e2['error'], 0, 90)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <div class="card"><h3>Recent tool errors</h3><p class="sub">Calls to your systems that failed</p>
    <?php if (!$m['tool_errors']): ?><div class="empty">No tool errors.</div><?php else: ?><div class="tablewrap"><table><tbody><?php foreach ($m['tool_errors'] as $e2): ?><tr><td class="small muted"><?= local_dt($e2['created_at'], 'M j H:i') ?></td><td class="mono"><?= e($e2['tool']) ?></td><td class="small"><?= e($e2['http_status']) ?> <?= e(mb_substr((string)$e2['error'], 0, 90)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>

<?php if ($m['failed_events']): ?>
<div class="card" style="margin-top:22px"><h3>Failed messages</h3><p class="sub">These customer messages could not be processed after several attempts. Retry them once the cause is fixed.</p>
  <div class="tablewrap"><table><thead><tr><th>Received</th><th>From</th><th>Message</th><th>Error</th><th></th></tr></thead><tbody>
  <?php foreach ($m['failed_events'] as $f): ?><tr><td class="small"><?= local_dt($f['received_at'], 'M j H:i') ?></td><td class="mono"><?= e($f['sender']) ?></td><td><?= e(mb_substr((string)$f['text'], 0, 60)) ?></td><td class="small down"><?= e(mb_substr((string)$f['last_error'], 0, 80)) ?></td>
    <td><?php if (Auth::isOwner()): ?><button class="btn sm" data-requeue="<?= $f['id'] ?>">Retry</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<div class="card" style="margin-top:22px"><h3>Questions the knowledge base could not answer</h3><p class="sub">Add these topics to <a href="<?= url('app/knowledge') ?>">Knowledge</a> to improve answers</p>
  <?php if (!$m['kb_misses']): ?><div class="empty">Nothing missing so far.</div><?php else: ?><table><tbody><?php foreach ($m['kb_misses'] as $k): ?><tr><td><?= e($k['query']) ?></td><td class="num"><?= $k['hits'] ?>×</td><td class="small muted"><?= ago($k['last_seen']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
