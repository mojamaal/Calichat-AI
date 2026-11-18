<?php
declare(strict_types=1);
/** Public invoice page: /invoice/{tenant}/{order_no}/{signature}. The signature proves the link was issued by CaliChat; nothing else is revealed. */
use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Core\Tenant;
use CaliChat\Services\Store;

$tid = (int)($seg[1] ?? 0); $no = strtoupper((string)($seg[2] ?? '')); $sig = (string)($seg[3] ?? '');
if (!Guard::allow('invoice:' . client_ip(), 60)) { http_response_code(429); exit('Too many requests'); }
$o = ($tid && preg_match('/^ORD-\d{3,8}$/', $no) && Store::invoiceValid($tid, $no, $sig)) ? Store::order($tid, $no) : null;
if (!$o) { http_response_code(404); header('Content-Type: text/plain'); exit('Invoice not found'); }
$tenant = Tenant::get($tid); set_tz((string)$tenant['timezone']);
$v = Store::orderView($o); $info = Store::settings($tid)['invoice_info'];
header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Invoice <?= e($v['order_no']) ?> · <?= e($tenant['name']) ?></title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f4fb;color:#15152b;margin:0;padding:24px 16px}
.inv{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e8e7f3;border-radius:14px;padding:28px}
h1{font-size:26px;margin:0 0 4px}.muted{color:#6a6a84}.small{font-size:13px}
.top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
table{width:100%;border-collapse:collapse;margin:18px 0}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eeedf7}th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6a6a84}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}.tot td{border-bottom:0;font-weight:700}
.badge{display:inline-block;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;background:#eef;color:#5b5bf0}.badge.paid{background:#e3f6ec;color:#168a4f}.badge.unpaid{background:#fdf0dc;color:#a8650b}
pre{white-space:pre-wrap;font:inherit;margin:0}
@media print{body{background:#fff;padding:0}.inv{border:0}}
</style></head><body><main class="inv">
<div class="top"><div><h1>Invoice</h1><div class="muted"><?= e($v['order_no']) ?> · <?= e(local_dt($v['placed_at'], 'j M Y')) ?></div></div>
  <div style="text-align:right"><b><?= e($tenant['name']) ?></b><?php if ($info !== ''): ?><div class="small muted"><pre><?= e($info) ?></pre></div><?php endif; ?></div></div>
<div class="top"><div><div class="small muted">Billed to</div><b><?= e($v['customer_name'] ?: 'Customer') ?></b><div class="small"><?= e($v['phone']) ?></div></div>
  <div><div class="small muted">Deliver to</div><div class="small"><?= e($v['delivery_address']) ?>, <?= e($v['delivery_city']) ?></div></div>
  <div style="text-align:right"><div class="small muted">Payment</div><div><?= e($v['payment_method']) ?></div><span class="badge <?= $v['payment_status'] === 'paid' ? 'paid' : 'unpaid' ?>"><?= e($v['payment_status'] === 'review' ? 'being verified' : $v['payment_status']) ?></span></div></div>
<table><thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Price</th><th class="n">Amount</th></tr></thead><tbody>
<?php foreach ($v['items'] as $i): ?><tr><td><?= e($i['name']) ?><?php $opt = trim(($i['size'] ?? '') . ' ' . ($i['color'] ?? '')); if ($opt !== ''): ?> <span class="muted small">(<?= e($opt) ?>)</span><?php endif; ?></td><td class="n"><?= (int)$i['qty'] ?></td><td class="n">Rs. <?= number_format((float)$i['unit_price'], 2) ?></td><td class="n">Rs. <?= number_format($i['qty'] * $i['unit_price'], 2) ?></td></tr><?php endforeach; ?>
<tr><td colspan="3" class="muted">Subtotal</td><td class="n">Rs. <?= number_format($v['subtotal'], 2) ?></td></tr>
<tr><td colspan="3" class="muted">Delivery</td><td class="n">Rs. <?= number_format($v['delivery_fee'], 2) ?></td></tr>
<tr class="tot"><td colspan="3">Total</td><td class="n">Rs. <?= number_format($v['total'], 2) ?></td></tr></tbody></table>
<p class="small muted">Thank you for shopping with <?= e($tenant['name']) ?>.</p>
</main></body></html>
