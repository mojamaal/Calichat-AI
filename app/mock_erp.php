<?php
declare(strict_types=1);
/**
 * Demo "customer ERP" API backed by the demo_* tables, so a fresh install works end-to-end.
 * Auth: Authorization: Bearer demo-erp-token
 *   GET   /mock/erp/categories
 *   GET   /mock/erp/products?search=&category=&in_stock=1&limit=20     GET /mock/erp/products/{sku}
 *   GET   /mock/erp/orders?phone=            GET /mock/erp/orders/{order_no}?phone=
 *   POST  /mock/erp/orders                   {phone, items:"SKU:SIZE:QTY,...", delivery_city, delivery_address, payment_method, customer_name?}
 *   PATCH /mock/erp/orders/{order_no}        {phone, delivery_city?, delivery_address?, payment_method?, items?}   (only while Processing)
 *   POST  /mock/erp/orders/{order_no}/cancel {phone, reason?}                                                       (Processing or Packed)
 *   GET   /mock/erp/leads?phone=             POST /mock/erp/leads {phone,name,interest,preferred_time?,notes?}
 *   POST  /mock/erp/leads/{id}/confirm       {phone}
 * Rules live here, like in a real ERP: prices come from the catalogue, stock is checked, an order belongs to one phone number.
 */
use CaliChat\Core\Db;

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$auth && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = $v; }
if (!hash_equals('Bearer demo-erp-token', (string)$auth)) json_out(['error' => 'unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input') ?: '', true); if (!is_array($body)) $body = [];
$phoneArg = preg_replace('/\D/', '', (string)($_GET['phone'] ?? ($body['phone'] ?? '')));
$route = trim(implode('/', array_slice($seg, 2)), '/');
const MAX_ORDER_TOTAL = 100000;

$product = function (array $r): array {
    return ['sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'], 'description' => $r['description'], 'price' => (float)$r['price'], 'currency' => $r['currency'],
        'stock' => (int)$r['stock'], 'in_stock' => (int)$r['stock'] > 0, 'variants' => $r['variants'] ? explode(',', $r['variants']) : [], 'colors' => $r['colors'] ? explode(',', $r['colors']) : [],
        'cost_price' => (float)$r['cost_price']]; // internal field on purpose: the tool's allow-list must keep it away from the AI
};
$order = function (array $o): array {
    $items = Db::all("SELECT sku, name, size, qty, unit_price FROM demo_order_items WHERE order_no=? ORDER BY id", [$o['order_no']]);
    foreach ($items as &$i) { $i['qty'] = (int)$i['qty']; $i['unit_price'] = (float)$i['unit_price']; }
    return ['order_no' => $o['order_no'], 'phone' => $o['phone'], 'customer_name' => $o['customer_name'], 'status' => $o['status'], 'courier' => $o['courier'], 'tracking' => $o['tracking'],
        'eta' => $o['eta'], 'payment_method' => $o['payment_method'], 'delivery_city' => $o['delivery_city'], 'delivery_address' => $o['delivery_address'], 'subtotal' => (float)$o['subtotal'],
        'delivery_fee' => (float)$o['delivery_fee'], 'total' => (float)$o['total'], 'items' => $items, 'placed_at' => $o['created_at'], 'internal_cost_note' => $o['internal_cost_note']];
};

// Idempotency: a repeated POST/PATCH with the same key returns the original result instead of acting twice.
$idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
if ($method !== 'GET' && $idem !== '') {
    $prev = Db::val("SELECT response FROM demo_idempotency WHERE idem_key=?", [hash('sha256', $idem)]);
    if ($prev !== null) { [$code, $payload] = json_decode($prev, true); $payload['idempotent_replay'] = true; json_out($payload, (int)$code); }
}
$reply = function (array $payload, int $code = 200) use ($method, $idem) {
    if ($method !== 'GET' && $idem !== '' && $code < 300) Db::q("INSERT IGNORE INTO demo_idempotency (idem_key, response) VALUES (?,?)", [hash('sha256', $idem), json_encode([$code, $payload])]);
    json_out($payload, $code);
};
$fail = fn(string $msg, int $code = 422) => json_out(['error' => $msg], $code);

/** "TS-001:M:1,SH-221:42:2" -> validated lines with catalogue data. Size "-" means no size. */
$parseItems = function (string $s) use ($fail): array {
    $lines = [];
    $parts = array_filter(array_map('trim', explode(',', $s)), 'strlen');
    if (!$parts || count($parts) > 8) $fail('items must contain 1 to 8 entries like SKU:SIZE:QTY');
    foreach ($parts as $p) {
        if (!preg_match('/^([A-Za-z]{2,4}-[0-9]{2,4}):([A-Za-z0-9-]{1,6}):([1-9][0-9]?)$/', $p, $m)) $fail("Bad item '$p'. Use SKU:SIZE:QTY, with - when there is no size.");
        $sku = strtoupper($m[1]); $size = $m[2] === '-' ? null : $m[2]; $qty = (int)$m[3];
        $pr = Db::one("SELECT * FROM demo_products WHERE sku=? AND is_active=1", [$sku]);
        if (!$pr) $fail("Unknown product $sku", 404);
        $variants = $pr['variants'] ? explode(',', $pr['variants']) : [];
        if ($variants && ($size === null || !in_array($size, $variants, true))) $fail("{$pr['name']} needs a size: " . implode(', ', $variants));
        if (!$variants && $size !== null) $size = null;
        $key = $sku . '|' . $size; if (isset($lines[$key])) $lines[$key]['qty'] += $qty; else $lines[$key] = ['sku' => $sku, 'name' => $pr['name'], 'size' => $size, 'qty' => $qty, 'price' => (float)$pr['price']];
    }
    return array_values($lines);
};
/** Take stock for lines inside the current transaction. */
$takeStock = function (array $lines) use ($fail) {
    foreach ($lines as $l) {
        $ok = Db::q("UPDATE demo_products SET stock = stock - ? WHERE sku=? AND stock >= ? AND is_active=1", [$l['qty'], $l['sku'], $l['qty']])->rowCount();
        if (!$ok) { $have = (int)Db::val("SELECT stock FROM demo_products WHERE sku=?", [$l['sku']]); Db::pdo()->rollBack(); $fail("Not enough stock for {$l['name']} (available: $have)", 409); }
    }
};
$returnStock = function (string $orderNo) {
    foreach (Db::all("SELECT sku, qty FROM demo_order_items WHERE order_no=?", [$orderNo]) as $i) Db::q("UPDATE demo_products SET stock = stock + ? WHERE sku=?", [$i['qty'], $i['sku']]);
};
$totals = function (array $lines): array { $sub = 0.0; foreach ($lines as $l) $sub += $l['price'] * $l['qty']; $fee = $sub >= 10000 ? 0.0 : 350.0; return [$sub, $fee, $sub + $fee]; };
$ownOrder = function (string $no) use ($phoneArg, $fail): array {
    $o = Db::one("SELECT * FROM demo_orders WHERE order_no=?", [strtoupper($no)]);
    if (!$o || $phoneArg === '' || $o['phone'] !== $phoneArg) $fail('order not found', 404); // never reveal that it exists
    return $o;
};
$payments = ['Cash on delivery', 'Card', 'Bank transfer'];

// ---------------------------------------------------------------- reads
if ($method === 'GET') {
    if ($route === 'categories') json_out(['data' => Db::all("SELECT category, COUNT(*) products, SUM(stock > 0) in_stock FROM demo_products WHERE is_active=1 GROUP BY category ORDER BY category")]);
    if ($route === 'products') {
        $w = 'is_active=1'; $p = [];
        $q = trim((string)($_GET['search'] ?? ''));
        if ($q !== '') { $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 60)) . '%'; $w .= ' AND (name LIKE ? OR sku LIKE ? OR category LIKE ? OR description LIKE ? OR colors LIKE ?)'; array_push($p, $like, $like, $like, $like, $like); }
        if (!empty($_GET['category'])) { $w .= ' AND category = ?'; $p[] = (string)$_GET['category']; }
        if (!empty($_GET['in_stock'])) $w .= ' AND stock > 0';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        json_out(['data' => array_map($product, Db::all("SELECT * FROM demo_products WHERE $w ORDER BY name LIMIT $limit", $p))]);
    }
    if (preg_match('#^products/([A-Za-z0-9\-]{2,20})$#', $route, $m)) {
        $r = Db::one("SELECT * FROM demo_products WHERE sku=? AND is_active=1", [strtoupper($m[1])]);
        $r ? json_out(['data' => $product($r)]) : $fail('product not found', 404);
    }
    if ($route === 'orders') {
        if ($phoneArg === '') $fail('phone is required');
        json_out(['data' => array_map($order, Db::all("SELECT * FROM demo_orders WHERE phone=? ORDER BY created_at DESC LIMIT 10", [$phoneArg]))]);
    }
    if (preg_match('#^orders/([A-Za-z0-9\-]{3,20})$#', $route, $m)) {
        $o = Db::one("SELECT * FROM demo_orders WHERE order_no=?", [strtoupper($m[1])]);
        if (!$o || ($phoneArg !== '' && $o['phone'] !== $phoneArg)) $fail('order not found', 404); // an order is only visible to its owner
        json_out(['data' => $order($o)]);
    }
    if ($route === 'leads') {
        if ($phoneArg === '') $fail('phone is required');
        json_out(['data' => Db::all("SELECT id, name, interest, preferred_time, status, created_at, confirmed_at FROM demo_leads WHERE phone=? ORDER BY id DESC LIMIT 10", [$phoneArg])]);
    }
    $fail('not found', 404);
}

// ---------------------------------------------------------------- writes
if ($phoneArg === '' || strlen($phoneArg) < 9) $fail('phone is required');

if ($method === 'POST' && $route === 'orders') { // create order
    $lines = $parseItems((string)($body['items'] ?? ''));
    $city = trim((string)($body['delivery_city'] ?? '')); $addr = trim((string)($body['delivery_address'] ?? '')); $pay = (string)($body['payment_method'] ?? 'Cash on delivery');
    if ($city === '' || $addr === '') $fail('delivery_city and delivery_address are required');
    if (!in_array($pay, $payments, true)) $fail('payment_method must be one of: ' . implode(', ', $payments));
    [$sub, $fee, $total] = $totals($lines);
    if ($total > MAX_ORDER_TOTAL) $fail('This order exceeds the online limit of Rs. ' . number_format(MAX_ORDER_TOTAL) . '. A team member must place it.', 422);
    Db::pdo()->beginTransaction();
    try {
        $takeStock($lines);
        $next = (int)Db::val("SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 5) AS UNSIGNED)), 3000) + 1 FROM demo_orders WHERE order_no REGEXP '^ORD-[0-9]+$' FOR UPDATE");
        $no = 'ORD-' . max(3001, $next);
        Db::insert('demo_orders', ['order_no' => $no, 'phone' => $phoneArg, 'customer_name' => mb_substr(trim((string)($body['customer_name'] ?? '')) ?: 'Customer', 0, 100), 'status' => 'Processing', 'eta' => 'Dispatch in 1-2 days',
            'payment_method' => $pay, 'delivery_city' => mb_substr($city, 0, 60), 'delivery_address' => mb_substr($addr, 0, 200), 'subtotal' => $sub, 'delivery_fee' => $fee, 'total' => $total, 'internal_cost_note' => 'created by AI assistant', 'created_at' => gmdate('Y-m-d H:i:s')]);
        foreach ($lines as $l) Db::insert('demo_order_items', ['order_no' => $no, 'sku' => $l['sku'], 'name' => $l['name'], 'size' => $l['size'], 'qty' => $l['qty'], 'unit_price' => $l['price']]);
        Db::pdo()->commit();
    } catch (\Throwable $e) { if (Db::pdo()->inTransaction()) Db::pdo()->rollBack(); throw $e; }
    $reply(['data' => $order(Db::one("SELECT * FROM demo_orders WHERE order_no=?", [$no])), 'message' => "Order $no placed. Total Rs. " . number_format($total, 2) . ($fee ? ' (includes delivery Rs. 350)' : ' (free delivery)') . '.'], 201);
}

if (preg_match('#^orders/([A-Za-z0-9\-]{3,20})/cancel$#', $route, $m) && $method === 'POST') { // cancel
    $o = $ownOrder($m[1]);
    if (!in_array($o['status'], ['Processing', 'Packed'], true)) $fail("Order {$o['order_no']} is {$o['status']} and can no longer be cancelled online. A team member can help.", 409);
    Db::pdo()->beginTransaction();
    $returnStock($o['order_no']);
    Db::q("UPDATE demo_orders SET status='Cancelled', eta='Cancelled at customer request', internal_cost_note=? WHERE order_no=?", [mb_substr(trim(($o['internal_cost_note'] ? $o['internal_cost_note'] . '; ' : '') . 'cancel reason: ' . (string)($body['reason'] ?? 'not given')), 0, 200), $o['order_no']]);
    Db::pdo()->commit();
    $reply(['data' => $order(Db::one("SELECT * FROM demo_orders WHERE order_no=?", [$o['order_no']])), 'message' => "Order {$o['order_no']} has been cancelled and stock released."]);
}

if (preg_match('#^orders/([A-Za-z0-9\-]{3,20})$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) { // update
    $o = $ownOrder($m[1]);
    if ($o['status'] !== 'Processing') $fail("Order {$o['order_no']} is {$o['status']} and can no longer be changed online. A team member can help.", 409);
    $set = [];
    foreach (['delivery_city' => 60, 'delivery_address' => 200] as $f => $max) if (isset($body[$f]) && trim((string)$body[$f]) !== '') $set[$f] = mb_substr(trim((string)$body[$f]), 0, $max);
    if (isset($body['payment_method']) && $body['payment_method'] !== '') { if (!in_array($body['payment_method'], $payments, true)) $fail('payment_method must be one of: ' . implode(', ', $payments)); $set['payment_method'] = $body['payment_method']; }
    $lines = isset($body['items']) && trim((string)$body['items']) !== '' ? $parseItems((string)$body['items']) : null;
    if (!$set && $lines === null) $fail('Nothing to update. Provide delivery_city, delivery_address, payment_method or items.');
    Db::pdo()->beginTransaction();
    try {
        if ($lines !== null) {
            [$sub, $fee, $total] = $totals($lines);
            if ($total > MAX_ORDER_TOTAL) { Db::pdo()->rollBack(); $fail('This order would exceed the online limit. A team member must change it.', 422); }
            $returnStock($o['order_no']); $takeStock($lines);
            Db::q("DELETE FROM demo_order_items WHERE order_no=?", [$o['order_no']]);
            foreach ($lines as $l) Db::insert('demo_order_items', ['order_no' => $o['order_no'], 'sku' => $l['sku'], 'name' => $l['name'], 'size' => $l['size'], 'qty' => $l['qty'], 'unit_price' => $l['price']]);
            $set += ['subtotal' => $sub, 'delivery_fee' => $fee, 'total' => $total];
        }
        Db::update('demo_orders', $set, 'order_no=?', [$o['order_no']]);
        Db::pdo()->commit();
    } catch (\Throwable $e) { if (Db::pdo()->inTransaction()) Db::pdo()->rollBack(); throw $e; }
    $reply(['data' => $order(Db::one("SELECT * FROM demo_orders WHERE order_no=?", [$o['order_no']])), 'message' => "Order {$o['order_no']} updated."]);
}

if ($method === 'POST' && $route === 'leads') { // create lead (de-duplicated per phone + interest per day)
    $name = trim((string)($body['name'] ?? '')); $interest = trim((string)($body['interest'] ?? ''));
    if ($name === '' || $interest === '') $fail('name and interest are required');
    $dup = Db::one("SELECT id FROM demo_leads WHERE phone=? AND interest=? AND created_at > NOW() - INTERVAL 1 DAY AND status<>'cancelled' LIMIT 1", [$phoneArg, mb_substr($interest, 0, 200)]);
    if ($dup) $reply(['data' => Db::one("SELECT id, name, interest, preferred_time, status FROM demo_leads WHERE id=?", [$dup['id']]), 'message' => 'You already have this enquiry open.', 'duplicate' => true]);
    $id = Db::insert('demo_leads', ['phone' => $phoneArg, 'name' => mb_substr($name, 0, 100), 'interest' => mb_substr($interest, 0, 200), 'preferred_time' => mb_substr(trim((string)($body['preferred_time'] ?? '')), 0, 80) ?: null,
        'notes' => mb_substr(trim((string)($body['notes'] ?? '')), 0, 300) ?: null, 'status' => 'new', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $reply(['data' => Db::one("SELECT id, name, interest, preferred_time, status FROM demo_leads WHERE id=?", [$id]), 'message' => "Enquiry #$id recorded. Our team will contact you."], 201);
}

if ($method === 'POST' && preg_match('#^leads/([0-9]{1,9})/confirm$#', $route, $m)) { // confirm lead
    $l = Db::one("SELECT * FROM demo_leads WHERE id=? AND phone=?", [(int)$m[1], $phoneArg]);
    if (!$l) $fail('lead not found', 404);
    if ($l['status'] !== 'new') $fail("Enquiry #{$l['id']} is already {$l['status']}.", 409);
    Db::update('demo_leads', ['status' => 'confirmed', 'confirmed_at' => gmdate('Y-m-d H:i:s')], 'id=?', [$l['id']]);
    $reply(['data' => Db::one("SELECT id, name, interest, preferred_time, status, confirmed_at FROM demo_leads WHERE id=?", [$l['id']]), 'message' => "Enquiry #{$l['id']} confirmed. A sales representative will contact you."]);
}
$fail('not found', 404);
