<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;
use CaliChat\Services\Billing;

Auth::requireOwner();
$title = 'Billing';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = post('act');
    if ($act === 'claim') { $r = Billing::claim($tenantId, (int)post('invoice_id'), post('ref')); Audit::log('invoice_claim', 'invoice:' . (int)post('invoice_id'), post('ref')); flash($r['ok'] ? 'Thank you. We will confirm your payment shortly.' : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); }
    if ($act === 'plan') {
        $p = Billing::plan(post('plan')); $months = max(1, min(12, (int)post('months', '1')));
        if (!$p || !(int)$p['is_public']) flash('Choose one of the listed plans.', 'err');
        else { $inv = Billing::issue($tenantId, $p['code'], $months); Audit::log('plan_request', $p['code'], "$months months"); flash("Invoice {$inv['number']} created for the {$p['name']} plan. Pay it by bank transfer and tell us the reference below."); }
    }
    redirect('app/billing');
}
$t = Tenant::get((int)$tenantId); $s = Billing::status($t); $plan = Billing::plan((string)$t['plan']); $plans = Billing::plans();
$invoices = Db::all("SELECT * FROM invoices WHERE tenant_id=? ORDER BY id DESC LIMIT 24", [$tenantId]);
$msgs = Tenant::monthMessages((int)$tenantId); $tok = Tenant::monthTokens((int)$tenantId); $users = (int)Db::val("SELECT COUNT(*) FROM users WHERE tenant_id=? AND status='active'", [$tenantId]);
$view = !empty($_GET['inv']) ? Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?", [(int)$_GET['inv'], $tenantId]) : null;
$bank = Billing::bankDetails(); $stateBadge = ['trial' => 'warn', 'active' => 'ok', 'past_due' => 'err', 'lapsed' => 'err', 'cancelled' => 'gray'];
?>
<div class="page-head"><div><h1>Plan &amp; <span class="accent">Billing</span></h1><p>Your plan, what you have used, and your invoices. You pay by bank transfer; there is no card on file.</p></div></div>
<?php if ($s['message'] && $s['state'] !== 'active'): ?><div class="card" style="margin-bottom:22px;background:var(--warn-bg);border-color:var(--warn)"><b><?= e($s['message']) ?></b></div><?php endif; ?>
<div class="grid" style="grid-template-columns:1.1fr 1fr;align-items:start;margin-bottom:22px">
  <div class="card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="margin:0"><?= e($plan['name'] ?? ucfirst((string)$t['plan'])) ?> plan</h3><span class="badge <?= $stateBadge[$s['state']] ?>"><?= e(str_replace('_', ' ', $s['state'])) ?></span></div>
    <p class="sub" style="margin:6px 0 14px"><?= $plan ? 'Rs. ' . num($plan['price_lkr']) . ' a month' : 'Custom plan' ?><?= $s['until'] ? ' · ' . ($s['state'] === 'trial' ? 'trial ends' : 'paid until') . ' ' . e(local_dt($s['until'], 'j M Y')) . ($s['days_left'] !== null ? ' (' . max(0, $s['days_left']) . ' days)' : '') : ' · no end date' ?></p>
    <?= gauge($t['monthly_message_limit'] ? 100 * $msgs / $t['monthly_message_limit'] : 0, 'AI replies this month', num($msgs) . ' of ' . num($t['monthly_message_limit'])) ?>
    <?= gauge($t['monthly_token_limit'] ? 100 * $tok / $t['monthly_token_limit'] : 0, 'AI usage this month', num($tok) . ' of ' . num($t['monthly_token_limit']) . ' tokens') ?>
    <?php if ($plan): ?><div class="small muted" style="margin-top:8px">Team members: <?= $users ?> of <?= (int)$plan['max_users'] ?></div><?php endif; ?></div>
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="plan"><h3>Choose or change your plan</h3>
    <label>Plan<select name="plan"><?php foreach ($plans as $p): ?><option value="<?= e($p['code']) ?>" <?= $p['code'] === $t['plan'] ? 'selected' : '' ?>><?= e($p['name']) ?>: Rs. <?= num($p['price_lkr']) ?> a month, <?= num($p['monthly_message_limit']) ?> replies</option><?php endforeach; ?></select></label>
    <label>Months to pay for<select name="months"><?php foreach ([1, 3, 6, 12] as $m): ?><option value="<?= $m ?>"><?= $m ?> month<?= $m > 1 ? 's' : '' ?></option><?php endforeach; ?></select></label>
    <button class="btn primary">Create invoice</button><p class="muted small" style="margin:0">The plan changes when your payment is confirmed. An open unpaid invoice is replaced.</p></form>
</div>
<?php if ($bank !== ''): ?><div class="card" style="margin-bottom:22px"><h3>How to pay</h3><pre style="white-space:pre-wrap;font:inherit;margin:0"><?= e($bank) ?></pre><p class="muted small" style="margin:8px 0 0">Put the invoice number in the transfer note, then press “I have paid” below with your bank reference.</p></div><?php endif; ?>
<?php if ($view): ?><div class="card" style="margin-bottom:22px;max-width:640px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h3 style="margin:0">Invoice <?= e($view['number']) ?></h3><div class="muted small">Issued <?= e(local_dt($view['issued_at'], 'j M Y')) ?> · due <?= e($view['due_date']) ?></div></div><a class="btn sm" href="<?= url('app/billing') ?>">Close</a></div>
  <p style="margin:14px 0 4px"><b><?= e($t['name']) ?></b></p><table><tbody><tr><td><?= e($view['description']) ?></td><td class="num"><b>Rs. <?= number_format((float)$view['amount'], 2) ?></b></td></tr></tbody></table><p class="small muted" style="margin:8px 0 0">Status: <?= e(str_replace('_', ' ', $view['status'])) ?><?= $view['payment_ref'] ? ' · reference ' . e($view['payment_ref']) : '' ?></p></div><?php endif; ?>
<div class="card"><h3>Invoices</h3><?php if (!$invoices): ?><div class="empty">No invoices yet.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Number</th><th>For</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($invoices as $i): ?><tr><td><a href="<?= url('app/billing?inv=' . $i['id']) ?>"><b><?= e($i['number']) ?></b></a><div class="muted small">due <?= e($i['due_date']) ?></div></td><td class="small"><?= e($i['description']) ?></td><td class="num">Rs. <?= num($i['amount']) ?></td>
  <td><span class="badge <?= $i['status'] === 'paid' ? 'ok' : ($i['status'] === 'void' ? 'gray' : 'warn') ?>"><?= e(str_replace('_', ' ', $i['status'])) ?></span><?= $i['payment_ref'] ? '<div class="muted small">' . e($i['payment_ref']) . '</div>' : '' ?></td>
  <td><?php if ($i['status'] === 'issued'): ?><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="act" value="claim"><input type="hidden" name="invoice_id" value="<?= $i['id'] ?>"><input name="ref" placeholder="Bank reference" required aria-label="Bank reference for <?= e($i['number']) ?>" style="width:150px;padding:6px 10px"><button class="btn sm primary">I have paid</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
