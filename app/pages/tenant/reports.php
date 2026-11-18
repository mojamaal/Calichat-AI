<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Services\Reports;

$title = 'Reports';
$dq = (int)($_GET['d'] ?? 30); $days = in_array($dq, [7, 30, 90], true) ? $dq : 30; // (a missing ?d= used to become 0 days)

if (isset($_GET['export']) && in_array($_GET['export'], ['orders', 'leads', 'conversations'], true)) {
    [$head, $rows] = Reports::export((int)$tenantId, $_GET['export'], $days, Auth::can('show_phone'));
    Audit::log('report_export', $_GET['export'], "$days days");
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $_GET['export'] . '-' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $head);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r)); // spreadsheet formula injection guard
    fclose($out); exit;
}

$r = Reports::summary((int)$tenantId, $days);
$dur = function (?int $s): string { if ($s === null) return '—'; if ($s < 90) return $s . ' s'; if ($s < 5400) return round($s / 60) . ' min'; return round($s / 3600, 1) . ' h'; };
$rs = fn($n) => 'Rs. ' . num($n);
$sourceNames = ['organic' => 'Direct / organic', 'click_to_whatsapp' => 'Click-to-WhatsApp ad', 'social_post' => 'Social post'];
$srcL = ['ai' => 'AI agent', 'manual' => 'Your team', 'ad_form' => 'Ad form', 'ad_click' => 'Ad click'];
?>
<div class="page-head"><div><h1>Business <span class="accent">Reports</span></h1><p>What your chats produced in the last <?= $days ?> days: orders, revenue, leads and bookings, and how quickly customers were answered.</p></div>
  <div class="actions"><?php foreach ([7, 30, 90] as $d): ?><a class="btn <?= $d === $days ? 'primary' : '' ?>" href="<?= url('app/reports?d=' . $d) ?>"><?= $d ?> days</a><?php endforeach; ?></div></div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:22px">
  <div class="card stat"><div class="muted small">Revenue from orders</div><div class="big"><?= $rs($r['revenue']) ?></div><div class="foot"><?= $r['orders'] ?> orders · average <?= $rs($r['aov']) ?></div></div>
  <div class="card stat"><div class="muted small">Placed by the AI agent</div><div class="big"><?= $rs($r['revenue_ai']) ?></div><div class="foot"><?= $r['orders_ai'] ?> orders · team <?= $r['orders_team'] ?> · catalogue <?= $r['orders_web'] ?></div></div>
  <div class="card stat"><div class="muted small">Chats that ended in an order</div><div class="big"><?= $r['chat_to_order_pct'] === null ? '—' : $r['chat_to_order_pct'] . '%' ?></div><div class="foot"><?= $r['conversations'] ?> conversations</div></div>
  <div class="card stat"><div class="muted small">First reply time (typical)</div><div class="big"><?= $dur($r['first_response_s']) ?></div><div class="foot">slowest 10%: <?= $dur($r['first_response_p90_s']) ?></div></div>
  <div class="card stat"><div class="muted small">Handled by AI alone</div><div class="big"><?= $r['ai_only_pct'] === null ? '—' : $r['ai_only_pct'] . '%' ?></div><div class="foot"><?= $r['handoffs'] ?> handed to people</div></div>
  <div class="card stat"><div class="muted small">Leads → won</div><div class="big"><?= $r['lead_win_pct'] === null ? '—' : $r['lead_win_pct'] . '%' ?></div><div class="foot"><?= $r['leads'] ?> leads · won <?= $rs($r['won_value']) ?></div></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start;margin-bottom:22px">
  <div class="card"><h3>Orders per day</h3><p class="sub">Excludes cancelled orders.</p><?= array_sum($r['daily_orders']) ? svg_bars($r['daily_orders']) : '<div class="empty">No orders in this period.</div>' ?></div>
  <div class="card"><h3>Revenue per day (Rs.)</h3><p class="sub">Excludes cancelled orders.</p><?= array_sum($r['daily_revenue']) ? svg_bars($r['daily_revenue'], 'var(--violet)') : '<div class="empty">No revenue in this period.</div>' ?></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start;margin-bottom:22px">
  <div class="card"><h3>How conversations ended</h3><div class="tablewrap"><table><tbody><?php foreach ($r['outcomes'] as $l => $n): ?><tr><td><?= e($l) ?></td><td class="num"><b><?= (int)$n ?></b></td><td class="num muted"><?= pct($n, $r['conversations']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <p class="muted small" style="margin:10px 0 0">Counted from what the AI really did in each chat (orders, leads and bookings that succeeded), not from guesses.</p></div>
  <div class="card"><h3>Where customers come from</h3><?php if (!$r['sources']): ?><div class="empty">No new contacts in this period.</div><?php else: ?><div class="tablewrap"><table><tbody><?php foreach ($r['sources'] as $s): ?><tr><td><?= e($sourceNames[$s['src']] ?? $s['src']) ?></td><td class="num"><b><?= (int)$s['n'] ?></b></td></tr><?php endforeach; ?></tbody></table></div>
    <?php if ($r['ads']): ?><div class="small" style="margin-top:10px"><b>Best ads:</b><?php foreach ($r['ads'] as $a): ?><div><?= e($a['ad'] ?: 'Unnamed ad') ?> · <?= (int)$a['n'] ?> chats</div><?php endforeach; ?></div><?php endif; ?><?php endif; ?>
    <?php if ($r['by_channel']): ?><div class="small muted" style="margin-top:10px">Channels: <?= e(implode(' · ', array_map(fn($c) => $c['channel_type'] . ' ' . $c['n'], $r['by_channel']))) ?></div><?php endif; ?></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start;margin-bottom:22px">
  <div class="card"><h3>Best-selling products</h3><?php if (!$r['top_products']): ?><div class="empty">No sales yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Product</th><th class="num">Sold</th><th class="num">Revenue</th></tr></thead><tbody><?php foreach ($r['top_products'] as $p): ?><tr><td><?= e($p['name']) ?><div class="muted small mono"><?= e($p['sku']) ?></div></td><td class="num"><?= (int)$p['qty'] ?></td><td class="num"><?= $rs($p['revenue']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <div class="card"><h3>Leads</h3><?php if (!$r['leads']): ?><div class="empty">No leads in this period.</div><?php else: ?>
    <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'won' => 'Won', 'lost' => 'Lost'] as $k => $l): $n = (int)($r['leads_by_stage'][$k] ?? 0); ?><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px"><span style="width:90px"><?= $l ?></span><div style="flex:1;background:var(--hover);border-radius:99px;height:10px"><i style="display:block;height:10px;border-radius:99px;background:var(--brand);width:<?= $r['leads'] ? round(100 * $n / $r['leads']) : 0 ?>%"></i></div><b style="width:30px;text-align:right"><?= $n ?></b></div><?php endforeach; ?>
    <div class="small muted" style="margin-top:8px">By source: <?= e(implode(' · ', array_map(fn($s) => ($srcL[$s['source']] ?? $s['source']) . ' ' . $s['n'] . ($s['won'] ? ' (' . $s['won'] . ' won)' : ''), $r['leads_by_source']))) ?>. Open pipeline: <?= $rs($r['pipeline_value']) ?>.</div><?php endif; ?></div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr;align-items:start;margin-bottom:22px">
  <div class="card"><h3>Team</h3><?php if (!$r['team']): ?><div class="empty">No team members.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Person</th><th class="num">Chats</th><th class="num">Replies</th><th class="num">Leads</th><th class="num">Won</th></tr></thead><tbody><?php foreach ($r['team'] as $t): ?><tr><td><?= e($t['name']) ?></td><td class="num"><?= (int)$t['chats'] ?></td><td class="num"><?= (int)$t['replies'] ?></td><td class="num"><?= (int)$t['leads'] ?></td><td class="num"><?= (int)$t['won'] ?></td></tr><?php endforeach; ?></tbody></table></div><p class="muted small" style="margin:8px 0 0">Chats and leads assigned to each person; replies are messages they typed in the Inbox.</p><?php endif; ?></div>
  <div class="card"><h3>Appointments and follow-ups</h3>
    <div class="small" style="margin-bottom:10px"><b>Appointments:</b> <?= e(implode(' · ', array_map(fn($k) => str_replace('_', ' ', $k) . ' ' . (int)($r['appointments'][$k] ?? 0), ['booked', 'completed', 'cancelled', 'no_show']))) ?></div>
    <?php if (!$r['followups']): ?><div class="muted small">No follow-ups ran in this period.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Follow-up</th><th class="num">Sent</th><th class="num">Skipped</th><th class="num">Failed</th></tr></thead><tbody><?php foreach ($r['followups'] as $f): ?><tr><td><?= e($f['name']) ?></td><td class="num"><?= (int)$f['sent'] ?></td><td class="num"><?= (int)$f['skipped'] ?></td><td class="num"><?= (int)$f['failed'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>

<div class="card"><h3>Download</h3><p class="sub">Spreadsheet files (CSV) for the last <?= $days ?> days.<?= Auth::can('show_phone') ? '' : ' Phone numbers are partly hidden for your account.' ?></p>
  <div style="display:flex;gap:10px;flex-wrap:wrap"><?php foreach (['orders' => 'Orders', 'leads' => 'Leads', 'conversations' => 'Conversations'] as $k => $l): ?><a class="btn" href="<?= url('app/reports?d=' . $days . '&export=' . $k) ?>"><?= $l ?></a><?php endforeach; ?></div></div>
