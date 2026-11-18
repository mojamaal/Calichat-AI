<?php
/** Tiny stand-in for the Meta Graph API, used only by tests/run.php (php -S 127.0.0.1:8766 tests/graph_stub.php). Every request is logged for assertions. */
$log = sys_get_temp_dir() . '/cc_graph_stub.log';
$path = preg_replace('#^/v[0-9.]+#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$rawBody = (string)file_get_contents('php://input');
file_put_contents($log, json_encode(['m' => $_SERVER['REQUEST_METHOD'], 'p' => $path, 'q' => $_GET, 'b' => $_POST, 'j' => json_decode($rawBody, true), 'auth' => $auth]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$err = function (int $c, string $m) { http_response_code($c); echo json_encode(['error' => ['message' => $m, 'code' => $c]]); exit; };

if ($path === '/oauth/access_token') {
    if (($_GET['client_secret'] ?? '') !== 'platform-secret' || ($_GET['client_id'] ?? '') !== '12345') $err(400, 'Invalid app credentials');
    if (($_GET['grant_type'] ?? '') === 'fb_exchange_token') { echo json_encode(['access_token' => 'LONG_' . ($_GET['fb_exchange_token'] ?? '')]); exit; }
    if (($_GET['code'] ?? '') === 'GOODCODE') { echo json_encode(['access_token' => 'SHORT']); exit; }
    if (($_GET['code'] ?? '') === 'WACODE') { echo json_encode(['access_token' => 'WA_BIZ_TOKEN']); exit; }
    $err(400, 'Invalid verification code format.');
}
if ($path === '/me/accounts') {
    if ($auth !== 'Bearer LONG_SHORT') $err(401, 'Invalid OAuth access token.');
    echo json_encode(['data' => [
        ['id' => '111', 'name' => 'Demo Page', 'access_token' => 'PAGE_TOKEN_111', 'instagram_business_account' => ['id' => '178', 'username' => 'demo_ig']],
        ['id' => '222', 'name' => 'Other Page', 'access_token' => 'PAGE_TOKEN_222'],
    ]]); exit;
}
// ---- stand-ins for other systems the app talks to (WooCommerce, Shopify, an SMS provider)
if ($path === '/wp-json/wc/v3/products') {
    if ($auth !== 'Basic ' . base64_encode('ck_test:cs_test')) $err(401, 'Invalid consumer key');
    echo json_encode(($_GET['page'] ?? '1') === '1' ? [
        ['id' => 11, 'name' => 'Woo Linen Shirt', 'sku' => 'wc-lin-1', 'price' => '4500', 'stock_quantity' => 7, 'manage_stock' => true, 'stock_status' => 'instock', 'categories' => [['name' => 'Shirts']], 'short_description' => '<p>Cool &amp; <b>breathable</b> linen.</p>', 'images' => [['src' => 'https://cdn.example.lk/linen.jpg']], 'attributes' => [['name' => 'Size', 'options' => ['S', 'M', 'L']], ['name' => 'Colour', 'options' => ['White', 'Sky Blue']]]],
        ['id' => 12, 'name' => 'Woo Tote', 'sku' => '', 'price' => '', 'regular_price' => '2500', 'stock_quantity' => null, 'stock_status' => 'outofstock', 'categories' => [], 'images' => [], 'attributes' => []],
    ] : []); exit;
}
if ($path === '/admin/api/2024-10/products.json') {
    if (($_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '') !== 'shpat_test') $err(401, 'Invalid API key or access token');
    echo json_encode(['products' => [['id' => 501, 'title' => 'Shopify Hoodie', 'product_type' => 'Tops', 'body_html' => '<p>Warm hoodie</p>', 'image' => ['src' => 'https://cdn.example.lk/hoodie.jpg'],
        'options' => [['name' => 'Size', 'values' => ['M', 'L']], ['name' => 'Color', 'values' => ['Black']]], 'variants' => [['sku' => 'SH-HOOD-M', 'price' => '6900.00', 'inventory_quantity' => 4], ['sku' => 'SH-HOOD-L', 'price' => '6900.00', 'inventory_quantity' => 3]]]]]); exit;
}
if ($path === '/sms/send' || $path === '/sms/fail') {
    file_put_contents(sys_get_temp_dir() . '/cc_sms_stub.log', json_encode(['m' => $_SERVER['REQUEST_METHOD'], 'q' => $_GET, 'raw' => $rawBody, 'auth' => $_SERVER['HTTP_X_API_KEY'] ?? '', 'ct' => $_SERVER['CONTENT_TYPE'] ?? '']) . "\n", FILE_APPEND);
    if ($path === '/sms/fail') $err(500, 'provider down'); echo json_encode(['status' => 'queued']); exit;
}
if (preg_match('#^/(\d+)$#', $path, $m) && strpos((string)($_GET['fields'] ?? ''), 'field_data') !== false) { // a Lead Ads lead
    if (strpos($auth, 'Bearer ') !== 0) $err(401, 'No token');
    echo json_encode(['id' => $m[1], 'created_time' => '2026-11-11T10:00:00+0000', 'ad_name' => 'Uniform offer', 'field_data' => [['name' => 'full_name', 'values' => ['Sunil Silva']], ['name' => 'phone_number', 'values' => ['+94 77 765 4321']], ['name' => 'email', 'values' => ['sunil@example.lk']], ['name' => 'how_many_uniforms?', 'values' => ['50 school uniforms']]]]); exit;
}
if (preg_match('#^/(\d+)/message_templates$#', $path, $m)) { // WhatsApp template management; state lives in a temp file so tests can "approve" templates
    if (strpos($auth, 'Bearer ') !== 0) $err(401, 'No token');
    $f = sys_get_temp_dir() . '/cc_stub_templates.json'; $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $j = json_decode($rawBody, true) ?: [];
        foreach ($all as $t) if ($t['name'] === ($j['name'] ?? '') && $t['language'] === ($j['language'] ?? '')) $err(400, 'Content in this language already exists');
        $id = (string)(9000 + count($all)); $status = strpos((string)($j['name'] ?? ''), 'bad') !== false ? 'REJECTED' : 'PENDING';
        $all[] = ['id' => $id, 'name' => $j['name'], 'language' => $j['language'], 'status' => $status, 'category' => $j['category'] ?? 'UTILITY', 'rejected_reason' => $status === 'REJECTED' ? 'INVALID_FORMAT' : null];
        file_put_contents($f, json_encode($all)); echo json_encode(['id' => $id, 'status' => $status, 'category' => $j['category'] ?? 'UTILITY']); exit;
    }
    echo json_encode(['data' => array_values($all)]); exit;
}
if (preg_match('#^/(\d+)/subscribed_apps$#', $path)) { if (strpos($auth, 'Bearer ') !== 0) $err(401, 'No token'); echo json_encode(['success' => true]); exit; }
if (preg_match('#^/(\d+)/media$#', $path)) { // voice-note upload
    if (strpos($auth, 'Bearer ') !== 0) $err(401, 'No token');
    $f = $_FILES['file'] ?? null; file_put_contents(sys_get_temp_dir() . '/cc_media_stub.log', json_encode(['type' => $_POST['type'] ?? null, 'mp' => $_POST['messaging_product'] ?? null, 'size' => $f ? $f['size'] : 0, 'ctype' => $f['type'] ?? null, 'head' => $f ? bin2hex(substr((string)file_get_contents($f['tmp_name']), 0, 4)) : null]) . "\n", FILE_APPEND);
    echo json_encode(['id' => 'MEDIA' . mt_rand(100, 999)]); exit;
}
if (preg_match('#^/(\d+)/messages$#', $path)) { if (strpos($auth, 'Bearer ') !== 0) $err(401, 'No token'); echo json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.stub' . mt_rand(1000, 9999)]]]); exit; }
if (preg_match('#^/(\d+)/register$#', $path)) { echo json_encode(['success' => true]); exit; }
if (preg_match('#^/(\d+)$#', $path, $m)) { echo json_encode(['id' => $m[1], 'display_phone_number' => '+94 77 123 4567', 'verified_name' => 'Demo Store']); exit; }
$err(404, 'Unknown path ' . $path);
