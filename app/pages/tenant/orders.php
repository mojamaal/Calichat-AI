<?php
use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Db;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

$title = 'Orders';
$badge = ['processing' => 'warn', 'packed' => '', 'shipped' => '', 'delivered' => 'ok', 'cancelled' => 'gray', 'returned' => 'err'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); Auth::requireCan('orders_edit');
    $act = post('act'); $no = post('order_no');
    try {
        if ($act === 'order_create') {
            $o = Store::createOrder($tenantId, ['phone' => post('phone'), 'customer_name' => post('customer_name'), 'items' => post('items'), 'delivery_city' => post('delivery_city'), 'delivery_address' => post('delivery_address'), 'payment_method' => post('payment_method'), 'notes' => post('notes')], 'manual');
            flash("Order {$o['order_no']} created. Total Rs. " . number_format((float)$o['total'], 2) . '.'); redirect('app/orders?o=' . $o['order_no']);
        }
        $o = Store::order($tenantId, $no); if (!$o) { flash('Order not found.', 'err'); redirect('app/orders'); }
        if ($act === 'order_status') {
            $to = post('status'); $n = Store::setStatus($tenantId, $o, $to, ['courier' => post('courier'), 'tracking' => post('tracking'), 'eta' => post('eta'), 'reason' => post('reason')]);
            Audit::log('store_order_status', 'order:' . $no, $o['status'] . ' -> ' . $to);
            $queued = (int)Db::val("SELECT COUNT(*) FROM automation_runs WHERE tenant_id=? AND subject_type='order' AND subject_id=? AND status='scheduled'", [$tenantId, $o['id']]);
            flash("Order $no is now " . ucfirst($to) . '.' . ($queued ? " $queued customer message(s) scheduled." : '')); redirect('app/orders?o=' . $no);
        }
        if ($act === 'slip_verify' || $act === 'slip_reject') { $r = \CaliChat\Services\Slips::decide($tenantId, (int)post('slip_id'), $act === 'slip_verify', (int)$u['id']); Audit::log('payment_slip_' . ($act === 'slip_verify' ? 'verify' : 'reject'), 'order:' . $no, 'slip ' . (int)post('slip_id')); flash($r['ok'] ? ($act === 'slip_verify' ? 'Payment verified and the order marked paid.' : 'Slip rejected. The order is unpaid again.') : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/orders?o=' . $no); }
        if ($act === 'order_invoice') {
            $r = \CaliChat\Services\Outbound::toPhone($tenantId, $o['phone'], 'Invoice for order ' . $o['order_no'] . ': ' . Store::invoiceUrl($tenantId, $o['order_no']), ['invoice' => $o['order_no']]);
            Audit::log('store_order_invoice', 'order:' . $no, $r['ok'] ? 'sent' : (string)$r['error']); flash($r['ok'] ? 'Invoice link sent to the customer.' : (string)$r['error'], $r['ok'] ? 'ok' : 'err'); redirect('app/orders?o=' . $no);
        }
        if ($act === 'order_paid') { Store::markPaid($tenantId, $o, post('state', 'paid')); Audit::log('store_order_payment', 'order:' . $no, post('state', 'paid')); flash('Payment status updated.'); redirect('app/orders?o=' . $no); }
        if ($act === 'order_update') { Store::updateOrder($tenantId, $o, ['delivery_city' => post('delivery_city'), 'delivery_address' => post('delivery_address'), 'payment_method' => post('payment_method')]); Audit::log('store_order_update', 'order:' . $no); flash('Order updated.'); redirect('app/orders?o=' . $no); }
    } catch (StoreError $e) { flash($e->getMessage(), 'err'); redirect('app/orders' . ($no ? '?o=' . urlencode($no) : '')); }
}

$cfg = Store::settings($tenantId);
$st = in_array($_GET['status'] ?? '', Store::STATUSES, true) ? $_GET['status'] : '';
$payReview = !empty($_GET['pay']) && $_GET['pay'] === 'review';
$toVerify = (int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=? AND payment_status='review'", [$tenantId]);
$q = trim($_GET['q'] ?? '');
$w = 'o.tenant_id=?'; $p = [$tenantId];
if ($st !== '') { $w .= ' AND o.status=?'; $p[] = $st; }
if ($payReview) $w .= " AND o.payment_status='review'";
if ($q !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $w .= ' AND (o.order_no LIKE ? OR o.phone LIKE ? OR o.customer_name LIKE ?)'; array_push($p, $like, $like, $like); }
$rows = Db::all("SELECT o.* FROM store_orders o WHERE $w ORDER BY o.id DESC LIMIT 200", $p);
$counts = array_column(Db::all("SELECT status, COUNT(*) n FROM store_orders WHERE tenant_id=? GROUP BY status", [$tenantId]), 'n', 'status');
$sel = !empty($_GET['o']) ? Store::order($tenantId, (string)$_GET['o']) : null;
$view = $sel ? Store::orderView($sel, true) : null;
$products = Db::all("SELECT sku, name, variants, colors, stock FROM store_products WHERE tenant_id=? AND is_active=1 ORDER BY name LIMIT 60", [$tenantId]);
?>
<div class="page-head"><div><h1>Customer <span class="accent">Orders</span></h1><p>Orders placed by the AI agent or by your team. Moving an order to Shipped or Delivered can message the customer automatically (Follow-ups).</p></div>
  <div class="actions"><a class="btn primary" href="#new"><?= icon('plus', 16) ?>New order</a></div></div>

<div class="tabs" style="padding-left:0"><?php foreach (['' => 'All'] + array_combine(Store::STATUSES, array_map('ucfirst', Store::STATUSES)) as $k => $l): ?>
  <a class="btn sm <?= $st === $k ? 'primary' : '' ?>" href="<?= url('app/orders' . ($k ? '?status=' . $k : '')) ?>"><?= e($l) ?><?= $k !== '' && !empty($counts[$k]) ? ' · ' . $counts[$k] : '' ?></a><?php endforeach; ?>
  <a class="btn sm <?= $payReview ? 'primary' : '' ?>" href="<?= url('app/orders?pay=review') ?>">Payments to verify<?= $toVerify ? ' · ' . $toVerify : '' ?></a>
  <form method="get" style="margin-left:auto"><?php if ($st): ?><input type="hidden" name="status" value="<?= e($st) ?>"><?php endif; ?><input name="q" value="<?= e($q) ?>" placeholder="Order no, phone or name" aria-label="Search orders" style="padding:7px 12px"></form></div>

<div class="grid" style="grid-template-columns:<?= $view ? '1.2fr 1fr' : '1fr' ?>;align-items:start;margin-top:14px">
<div class="card"><?php if (!$rows): ?><div class="empty">No orders<?= $st || $q ? ' match this filter' : ' yet. They appear here when the AI agent or your team creates them' ?>.</div><?php else: ?>
  <div class="tablewrap"><table><thead><tr><th>Order</th><th>Customer</th><th class="num">Total</th><th>Payment</th><th>Status</th><th>Placed</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr style="<?= $sel && $sel['id'] === $r['id'] ? 'background:var(--brand-50)' : '' ?>">
    <td><a href="<?= url('app/orders?o=' . $r['order_no'] . ($st ? '&status=' . $st : '')) ?>"><b><?= e($r['order_no']) ?></b></a><div class="muted small"><?= $r['source'] === 'ai' ? 'by AI agent' : ($r['source'] === 'catalogue' ? 'from catalogue' : 'by team') ?></div></td>
    <td><?= e($r['customer_name'] ?: '—') ?><div class="muted small mono"><?= e(phone_view($r['phone'])) ?></div></td><td class="num">Rs. <?= num($r['total']) ?></td>
    <td class="small"><?= e($r['payment_method']) ?><br><span class="badge <?= $r['payment_status'] === 'paid' ? 'ok' : ($r['payment_status'] === 'refunded' ? 'err' : 'gray') ?>"><?= e($r['payment_status']) ?></span></td>
    <td><span class="badge <?= $badge[$r['status']] ?>"><?= e(ucfirst($r['status'])) ?></span></td><td class="muted small"><?= ago($r['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php if ($view): $nexts = Store::next($sel['status']); ?>
<div class="stack">
  <div class="card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="margin:0"><?= e($view['order_no']) ?></h3><span class="badge <?= $badge[$sel['status']] ?>"><?= e($view['status']) ?></span><span class="badge <?= $sel['payment_status'] === 'paid' ? 'ok' : 'gray' ?>"><?= e($sel['payment_status']) ?></span>
      <a class="btn sm" style="margin-left:auto" href="<?= url('app/orders' . ($st ? '?status=' . $st : '')) ?>">Close</a></div>
    <p class="sub" style="margin:6px 0 14px"><?= e($view['customer_name']) ?> · <span class="mono"><?= e(phone_view($view['phone'])) ?></span> · placed <?= local_dt($view['placed_at']) ?></p>
    <div class="tablewrap"><table><tbody><?php foreach ($view['items'] as $i): ?><tr><td><?= e($i['name']) ?><?= $i['size'] || $i['color'] ? ' <span class="muted small">(' . e(trim(($i['size'] ?? '') . ' ' . ($i['color'] ?? ''))) . ')</span>' : '' ?></td><td class="num"><?= $i['qty'] ?> × Rs. <?= num($i['unit_price']) ?></td><td class="num">Rs. <?= num($i['qty'] * $i['unit_price']) ?></td></tr><?php endforeach; ?>
      <tr><td colspan="2" class="muted">Delivery to <?= e($view['delivery_city']) ?></td><td class="num">Rs. <?= num($view['delivery_fee']) ?></td></tr>
      <tr><td colspan="2"><b>Total</b></td><td class="num"><b>Rs. <?= num($view['total']) ?></b></td></tr></tbody></table></div>
    <p class="small" style="margin:12px 0 0"><b>Deliver to:</b> <?= e($view['delivery_address']) ?>, <?= e($view['delivery_city']) ?><?php if ($view['courier'] || $view['tracking']): ?><br><b>Courier:</b> <?= e($view['courier'] ?: '—') ?> · <b>Tracking:</b> <span class="mono"><?= e($view['tracking'] ?: '—') ?></span><?php endif; ?><?php if ($view['eta']): ?><br><b>ETA:</b> <?= e($view['eta']) ?><?php endif; ?><?php if ($view['notes']): ?><br><b>Notes:</b> <?= e($view['notes']) ?><?php endif; ?></p></div>

  <?php foreach (Db::all("SELECT * FROM payment_slips WHERE tenant_id=? AND order_id=? ORDER BY id DESC", [$tenantId, $sel['id']]) as $sl): ?><div class="card" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start"><a href="<?= url('api/slips/file?id=' . $sl['id']) ?>" target="_blank" rel="noopener"><img src="<?= url('api/slips/file?id=' . $sl['id']) ?>" alt="Payment slip" style="width:120px;height:120px;object-fit:cover;border-radius:10px;border:1px solid var(--line)"></a>
    <div style="flex:1;min-width:200px"><h3 style="font-size:15px;margin:0">Payment slip <span class="badge <?= $sl['status'] === 'verified' ? 'ok' : ($sl['status'] === 'rejected' ? 'err' : 'warn') ?>"><?= e($sl['status']) ?></span></h3>
      <div class="small">Amount: <b><?= $sl['amount'] !== null ? 'Rs. ' . num($sl['amount']) : 'not readable' ?></b> <?= $sl['amount_matches'] === null ? '' : ($sl['amount_matches'] ? '<span class="badge ok">matches total</span>' : '<span class="badge err">differs from total</span>') ?><br>Reference: <?= e($sl['reference'] ?: '—') ?> · Bank: <?= e($sl['bank'] ?: '—') ?> · Date on slip: <?= e($sl['paid_on'] ?: '—') ?><br><span class="muted">Read by AI (<?= e($sl['confidence'] ?: 'n/a') ?> confidence). Always compare with your bank statement.</span></div>
      <?php if ($sl['status'] === 'pending'): ?><div style="display:flex;gap:8px;margin-top:8px"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="slip_verify"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><input type="hidden" name="slip_id" value="<?= $sl['id'] ?>"><button class="btn sm primary">Payment received</button></form><form method="post" data-confirm="Reject this slip? The customer is asked to send another."><?= csrf_field() ?><input type="hidden" name="act" value="slip_reject"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><input type="hidden" name="slip_id" value="<?= $sl['id'] ?>"><button class="btn sm danger">Reject</button></form></div><?php endif; ?></div></div><?php endforeach; ?>
  <?php if ($nexts): ?><form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="order_status"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><h3>Move this order</h3>
    <label>New status<select name="status"><?php foreach ($nexts as $n): ?><option value="<?= $n ?>"><?= ucfirst($n) ?></option><?php endforeach; ?></select></label>
    <div class="row"><label>Courier <small>for Shipped</small><input name="courier" value="<?= e($view['courier']) ?>" placeholder="Domex, Pronto, CityPak"></label><label>Tracking number<input name="tracking" value="<?= e($view['tracking']) ?>"></label></div>
    <div class="row"><label>Delivery estimate<input name="eta" value="<?= e($view['eta']) ?>" placeholder="Arrives in 2 days"></label><label>Reason <small>for Cancelled</small><input name="reason"></label></div>
    <button class="btn primary">Update status</button><p class="muted small" style="margin:0">Cancelling puts the stock back. Customers are messaged only if a matching follow-up is switched on.</p></form><?php endif; ?>
  <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><b class="small">Invoice</b><a class="btn sm" href="<?= e(Store::invoiceUrl($tenantId, $view['order_no'])) ?>" target="_blank" rel="noopener">Open</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="order_invoice"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><button class="btn sm">Send link to customer</button></form></div>
  <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><b class="small">Payment</b>
    <?php foreach (['paid' => 'Mark paid', 'unpaid' => 'Mark unpaid', 'refunded' => 'Mark refunded'] as $k => $l): if ($sel['payment_status'] === $k) continue; ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="order_paid"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><input type="hidden" name="state" value="<?= $k ?>"><button class="btn sm"><?= $l ?></button></form><?php endforeach; ?></div>
  <?php if ($sel['status'] === 'processing'): ?><form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="order_update"><input type="hidden" name="order_no" value="<?= e($view['order_no']) ?>"><h3>Change delivery details</h3>
    <div class="row"><label>City<input name="delivery_city" value="<?= e($view['delivery_city']) ?>"></label><label>Payment<select name="payment_method"><?php foreach ($cfg['payments'] as $m): ?><option <?= $m === $view['payment_method'] ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label></div>
    <label>Address<input name="delivery_address" value="<?= e($view['delivery_address']) ?>"></label><button class="btn">Save</button></form><?php endif; ?>
</div><?php endif; ?>
</div>

<?php if (Auth::can('orders_edit')): ?><form method="post" class="card stack" id="new" style="margin-top:22px;max-width:860px"><?= csrf_field() ?><input type="hidden" name="act" value="order_create"><h3>Create an order</h3>
  <p class="sub" style="margin:0">For orders that come in by phone, in the shop or from social media. Prices and delivery come from your catalogue, stock is reserved.</p>
  <div class="row"><label>Customer phone <small>with country code</small><input name="phone" required placeholder="94771234567"></label><label>Customer name<input name="customer_name"></label></div>
  <label>Items <small>SKU:SIZE:QTY:COLOUR, comma separated. Use - when there is no size, e.g. <span class="mono">TS-001:M:2:Black,AC-503:-:1:Khaki</span></small><input name="items" required></label>
  <div class="row"><label>City<input name="delivery_city" required placeholder="Colombo"></label><label>Payment<select name="payment_method"><?php foreach ($cfg['payments'] as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></label></div>
  <label>Delivery address<input name="delivery_address" required></label><label>Internal note<input name="notes"></label>
  <button class="btn primary">Create order</button>
  <?php if ($products): ?><details><summary class="small muted" style="cursor:pointer">Product codes</summary><div class="tablewrap"><table><thead><tr><th>SKU</th><th>Product</th><th>Sizes</th><th>Colours</th><th class="num">Stock</th></tr></thead><tbody><?php foreach ($products as $pr): ?><tr><td class="mono"><?= e($pr['sku']) ?></td><td><?= e($pr['name']) ?></td><td class="small"><?= e($pr['variants'] ?: '—') ?></td><td class="small"><?= e($pr['colors'] ?: '—') ?></td><td class="num"><?= (int)$pr['stock'] ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</form>
<?php endif; ?>
