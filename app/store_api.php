<?php
declare(strict_types=1);
/**
 * Built-in store API (what the AI's tools call when a business uses CaliChat's own store instead of an external ERP).
 * Auth: Authorization: Bearer st_<token>   (one token per tenant; the tenant comes from the token, never from the request)
 *   GET   /store/v1/categories
 *   GET   /store/v1/products?search=&category=&in_stock=1&limit=20     GET /store/v1/products/{sku}
 *   GET   /store/v1/delivery?city=&subtotal=
 *   GET   /store/v1/orders?phone=            GET /store/v1/orders/{order_no}?phone=
 *   POST  /store/v1/orders                   {phone, items:"SKU:SIZE:QTY,...", delivery_city, delivery_address, payment_method, customer_name?}
 *   PATCH /store/v1/orders/{order_no}        {phone, delivery_city?, delivery_address?, payment_method?, items?}   (only while Processing)
 *   POST  /store/v1/orders/{order_no}/cancel {phone, reason?}                                                       (Processing or Packed)
 *   GET   /store/v1/leads?phone=             POST /store/v1/leads {phone,name,interest,preferred_time?,notes?}
 *   POST  /store/v1/leads/{id}/confirm       {phone}
 * Business rules live in src/Services/Store.php, shared with the dashboard pages.
 */
use CaliChat\Core\Db;
use CaliChat\Services\Appointments;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$auth && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = $v; }
$tid = Store::tenantForToken((string)$auth);
if (!$tid) json_out(['error' => 'unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input') ?: '', true); if (!is_array($body)) $body = [];
$phoneArg = Store::digits((string)($_GET['phone'] ?? ($body['phone'] ?? '')));
$route = trim(implode('/', array_slice($seg, 2)), '/');
$fail = fn(string $msg, int $code = 422) => json_out(['error' => $msg], $code);

// Idempotency: a repeated POST/PATCH with the same key returns the original result instead of acting twice.
$idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''); $idemHash = hash('sha256', $idem);
if ($method !== 'GET' && $idem !== '') {
    $prev = Db::val("SELECT response FROM store_idempotency WHERE tenant_id=? AND idem_key=?", [$tid, $idemHash]);
    if ($prev !== null) { [$code, $payload] = json_decode($prev, true); $payload['idempotent_replay'] = true; json_out($payload, (int)$code); }
}
$reply = function (array $payload, int $code = 200) use ($method, $idem, $idemHash, $tid) {
    if ($method !== 'GET' && $idem !== '' && $code < 300) Db::q("INSERT IGNORE INTO store_idempotency (tenant_id, idem_key, response) VALUES (?,?,?)", [$tid, $idemHash, json_encode([$code, $payload])]);
    json_out($payload, $code);
};
$ownOrder = function (string $no) use ($tid, $phoneArg, $fail): array {
    $o = Store::order($tid, $no);
    if (!$o || $phoneArg === '' || $o['phone'] !== $phoneArg) $fail('order not found', 404); // never reveal that it exists
    return $o;
};

try {
    if ($method === 'GET') {
        if ($route === 'categories') json_out(['data' => Db::all("SELECT category, COUNT(*) products, SUM(stock > 0) in_stock FROM store_products WHERE tenant_id=? AND is_active=1 GROUP BY category ORDER BY category", [$tid])]);
        if ($route === 'products') {
            $w = 'tenant_id=? AND is_active=1'; $p = [$tid];
            $q = trim((string)($_GET['search'] ?? ''));
            // Every word must match somewhere ("black baseball cap" finds the Baseball Cap that comes in Black).
            foreach (array_slice(preg_split('/\s+/u', mb_substr($q, 0, 60), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $word) . '%'; $w .= ' AND (name LIKE ? OR sku LIKE ? OR category LIKE ? OR description LIKE ? OR colors LIKE ? OR variants LIKE ?)'; array_push($p, $like, $like, $like, $like, $like, $like);
            }
            if (!empty($_GET['category'])) { $w .= ' AND category = ?'; $p[] = (string)$_GET['category']; }
            if (!empty($_GET['in_stock'])) $w .= ' AND stock > 0';
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            json_out(['data' => array_map([Store::class, 'productView'], Db::all("SELECT * FROM store_products WHERE $w ORDER BY name LIMIT $limit", $p))]);
        }
        if (preg_match('#^products/([A-Za-z0-9\-]{2,30})$#', $route, $m)) {
            $r = Db::one("SELECT * FROM store_products WHERE tenant_id=? AND sku=? AND is_active=1", [$tid, strtoupper($m[1])]);
            $r ? json_out(['data' => Store::productView($r)]) : $fail('product not found', 404);
        }
        if ($route === 'delivery') {
            $city = trim((string)($_GET['city'] ?? '')); if ($city === '') $fail('city is required');
            json_out(['data' => Store::delivery($tid, mb_substr($city, 0, 60), (float)($_GET['subtotal'] ?? 0))]);
        }
        if ($route === 'services') json_out(['data' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'duration_min' => (int)$s['duration_min'], 'price' => (float)$s['price']], Appointments::services($tid))]);
        if ($route === 'slots') {
            $sid = (int)($_GET['service_id'] ?? 0); $date = (string)($_GET['date'] ?? '');
            $slots = Appointments::slots($tid, $sid, $date);
            json_out(['data' => ['date' => $date, 'service_id' => $sid, 'free_times' => array_slice($slots, 0, 40), 'note' => $slots ? 'Book one of these exact times.' : 'Nothing free on that day (closed, fully booked or in the past). Try another date.']]);
        }
        if ($route === 'appointments') {
            if ($phoneArg === '') $fail('phone is required');
            json_out(['data' => array_map(fn($a) => Appointments::view($tid, $a), Db::all("SELECT * FROM appointments WHERE tenant_id=? AND phone=? ORDER BY start_at DESC LIMIT 10", [$tid, $phoneArg]))]);
        }
        if ($route === 'customer') { // what we already know about this customer (from earlier orders and what they told the assistant)
            if ($phoneArg === '') $fail('phone is required');
            json_out(['data' => Store::profile($tid, $phoneArg) ?: ['name' => null, 'address' => null, 'city' => null, 'payment_method' => null, 'orders_count' => 0, 'last_order_at' => null]]);
        }
        if ($route === 'orders/check') { // dry run: validate extracted order details, quote prices, report what is missing. Changes nothing.
            $existing = null;
            if (!empty($_GET['order_no'])) { $existing = Store::order($tid, (string)$_GET['order_no']); if (!$existing || $phoneArg === '' || $existing['phone'] !== $phoneArg) $fail('order not found', 404); }
            $q = $_GET; if (trim((string)($q['customer_name'] ?? '')) === '' && $phoneArg !== '' && ($pf = Store::profile($tid, $phoneArg)) && !empty($pf['name'])) $q['customer_name'] = $pf['name']; // a name we already know is not 'missing'
            json_out(['data' => Store::check($tid, $q, $existing)]);
        }
        if ($route === 'orders') {
            if ($phoneArg === '') $fail('phone is required');
            json_out(['data' => array_map([Store::class, 'orderView'], Db::all("SELECT * FROM store_orders WHERE tenant_id=? AND phone=? ORDER BY created_at DESC, id DESC LIMIT 10", [$tid, $phoneArg]))]);
        }
        if (preg_match('#^orders/([A-Za-z0-9\-]{3,20})/invoice$#', $route, $m)) { $o = $ownOrder($m[1]); json_out(['data' => ['order_no' => $o['order_no'], 'invoice_url' => Store::invoiceUrl($tid, $o['order_no'])]]); }
        if (preg_match('#^orders/([A-Za-z0-9\-]{3,20})$#', $route, $m)) json_out(['data' => Store::orderView($ownOrder($m[1]))]);
        if ($route === 'leads') {
            if ($phoneArg === '') $fail('phone is required');
            json_out(['data' => array_map([Store::class, 'leadView'], Db::all("SELECT * FROM crm_leads WHERE tenant_id=? AND phone=? ORDER BY id DESC LIMIT 10", [$tid, $phoneArg]))]);
        }
        $fail('not found', 404);
    }

    if ($phoneArg === '' || strlen($phoneArg) < 9) $fail('phone is required');

    if ($method === 'POST' && $route === 'appointments') {
        $a = Appointments::book($tid, (int)($body['service_id'] ?? 0), $phoneArg, (string)($body['customer_name'] ?? ''), (string)($body['start'] ?? ''), 'ai'); $v = Appointments::view($tid, $a);
        $reply(['data' => $v, 'message' => "Booked: {$v['service']} on {$v['day']} at {$v['time']}."], 201);
    }
    if ($method === 'POST' && preg_match('#^appointments/([0-9]{1,9})/cancel$#', $route, $m)) {
        $a = Appointments::setStatus($tid, (int)$m[1], 'cancelled', $phoneArg); $v = Appointments::view($tid, $a);
        $reply(['data' => $v, 'message' => "Appointment on {$v['day']} at {$v['time']} has been cancelled."]);
    }
    if ($method === 'POST' && $route === 'customer') { // remember details the customer gave (name, address, town, usual payment); the customer's own data, so no confirmation round-trip
        Store::saveProfile($tid, $phoneArg, $body); $reply(['data' => Store::profile($tid, $phoneArg), 'message' => 'Customer details saved.']);
    }
    if ($method === 'POST' && $route === 'orders') {
        if (trim((string)($body['customer_name'] ?? '')) === '' && ($pf = Store::profile($tid, $phoneArg)) && !empty($pf['name'])) $body['customer_name'] = $pf['name'];
        $o = Store::createOrder($tid, $body, 'ai'); $v = Store::orderView($o);
        $reply(['data' => $v, 'message' => "Order {$o['order_no']} placed. Total Rs. " . number_format((float)$o['total'], 2) . ((float)$o['delivery_fee'] > 0 ? ' (includes delivery Rs. ' . number_format((float)$o['delivery_fee']) . ')' : ' (free delivery)') . '.'], 201);
    }
    if ($method === 'POST' && preg_match('#^orders/([A-Za-z0-9\-]{3,20})/cancel$#', $route, $m)) {
        $o = $ownOrder($m[1]);
        if (!in_array($o['status'], ['processing', 'packed'], true)) $fail("Order {$o['order_no']} is " . ucfirst($o['status']) . ' and can no longer be cancelled online. A team member can help.', 409);
        $o = Store::setStatus($tid, $o, 'cancelled', ['reason' => (string)($body['reason'] ?? 'not given')]);
        $reply(['data' => Store::orderView($o), 'message' => "Order {$o['order_no']} has been cancelled and stock released."]);
    }
    if (in_array($method, ['PATCH', 'PUT'], true) && preg_match('#^orders/([A-Za-z0-9\-]{3,20})$#', $route, $m)) {
        $o = Store::updateOrder($tid, $ownOrder($m[1]), $body);
        $reply(['data' => Store::orderView($o), 'message' => "Order {$o['order_no']} updated."]);
    }
    if ($method === 'POST' && $route === 'leads') {
        $r = Store::createLead($tid, $body, 'ai'); $v = Store::leadView($r['lead']);
        if ($r['duplicate']) $reply(['data' => $v, 'message' => 'You already have this enquiry open.', 'duplicate' => true]);
        $reply(['data' => $v, 'message' => "Enquiry #{$v['id']} recorded. Our team will contact you."], 201);
    }
    if ($method === 'POST' && preg_match('#^leads/([0-9]{1,9})/confirm$#', $route, $m)) {
        $l = Store::confirmLead($tid, (int)$m[1], $phoneArg);
        $reply(['data' => Store::leadView($l), 'message' => "Enquiry #{$l['id']} confirmed. A sales representative will contact you."]);
    }
    $fail('not found', 404);
} catch (StoreError $e) {
    $fail($e->getMessage(), $e->getCode() ?: 422);
}
