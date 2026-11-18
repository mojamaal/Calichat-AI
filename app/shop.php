<?php
declare(strict_types=1);
/**
 * Customer-facing catalogue: /shop/{tenant}/{phone|preview}/{expires}/{signature}[/quote|/order]
 * The signed link proves CaliChat issued it to that customer (no login). The page shows the business's branded catalogue; the customer adds products and
 * quantities, removes items, sees the order summary with the delivery fee, and confirms or cancels. All rules are enforced by Store, not by this file.
 */
use CaliChat\Core\Db;
use CaliChat\Core\Guard;
use CaliChat\Core\Tenant;
use CaliChat\Services\Brand;
use CaliChat\Services\Catalogue;
use CaliChat\Services\Store;
use CaliChat\Services\StoreError;

if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // the catalogue is public and never uses the session
$tid = (int)($seg[1] ?? 0); $who = (string)($seg[2] ?? ''); $exp = (int)($seg[3] ?? 0); $sig = (string)($seg[4] ?? ''); $action = (string)($seg[5] ?? '');
$preview = $who === 'preview'; $phone = $preview ? 'preview' : Store::digits($who);
if (!Guard::allow('shop:' . client_ip(), 240)) { http_response_code(429); exit('Too many requests'); }
$tenant = $tid > 0 ? Tenant::get($tid) : null;
if (!$tenant || $tenant['status'] !== 'active' || ($phone !== 'preview' && strlen($phone) < 9) || !Catalogue::valid($tid, $phone, $exp, $sig)) {
    http_response_code(404); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex');
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link expired</title><body style="font-family:system-ui;text-align:center;padding:64px 20px;color:#15152b"><h2>This catalogue link has expired</h2><p style="color:#6a6a84">Please message us again on WhatsApp and we will send you a fresh link.</p></body>');
}
set_tz((string)$tenant['timezone']);

// ---- JSON actions (quote the cart, place the order)
if ($action === 'quote' || $action === 'order') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST only'], 405);
    $in = json_decode(file_get_contents('php://input') ?: '', true); if (!is_array($in)) json_out(['error' => 'Invalid request'], 400);
    try {
        if ($action === 'quote') json_out(['ok' => true] + Catalogue::quote($tid, (array)($in['cart'] ?? []), mb_substr(trim((string)($in['city'] ?? '')), 0, 60), trim((string)($in['payment'] ?? ''))));
        if ($preview) json_out(['ok' => false, 'error' => 'This is a preview: ordering is switched off.'], 403);
        if (!Guard::allow('shoporder:' . $tid . ':' . $phone, 8)) json_out(['ok' => false, 'error' => 'Too many attempts. Please wait a minute and try again.'], 429);
        $o = Catalogue::place($tid, $phone, $in); $v = Store::orderView($o);
        json_out(['ok' => true, 'order' => ['order_no' => $v['order_no'], 'total' => $v['total'], 'delivery_fee' => $v['delivery_fee'], 'subtotal' => $v['subtotal'], 'eta' => $v['eta'], 'payment' => $v['payment_method'], 'items' => $v['items'], 'invoice_url' => Store::invoiceUrl($tid, $v['order_no'])]]);
    } catch (StoreError $e) { json_out(['ok' => false, 'error' => $e->getMessage()], 422); }
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }

// ---- the page
$override = $preview && isset($_GET['d']) && is_array($_GET['d']) ? Brand::sanitize($_GET['d'] + ['_catalogue_form' => 1]) : [];
$b = Brand::get($tid, $override); $products = Catalogue::products($tid, $b);
$s = Store::settings($tid); $pf = !$preview ? Store::profile($tid, $phone) : null;
$zones = array_column(Db::all("SELECT town FROM store_zones WHERE tenant_id=? ORDER BY town", [$tid]), 'town');
$boot = ['brand' => ['name' => $b['name'], 'tagline' => $b['brand_tagline'], 'color' => $b['brand_color'], 'logo' => $b['logo_url'], 'phone' => $b['brand_phone'], 'address' => $b['brand_address'], 'layout' => $b['cat_layout'], 'theme' => $b['cat_theme'], 'corner' => $b['cat_corner'], 'columns' => $b['cat_columns'],
        'title' => $b['cat_title'] !== '' ? $b['cat_title'] : 'Our catalogue', 'intro' => $b['cat_intro'], 'button' => $b['cat_button'], 'footer' => $b['cat_footer'], 'showStock' => (bool)$b['cat_show_stock'], 'showDesc' => (bool)$b['cat_show_desc'], 'showSku' => (bool)$b['cat_show_sku']],
    'products' => $products, 'categories' => Catalogue::categories($products), 'payments' => $s['payments'], 'towns' => $zones, 'defaultFee' => $s['default_fee'], 'freeOver' => $s['free_over'],
    'profile' => $pf ? ['name' => (string)$pf['name'], 'address' => (string)$pf['address'], 'city' => (string)$pf['city'], 'payment' => (string)$pf['payment_method']] : null,
    'preview' => $preview, 'category' => (string)($_GET['c'] ?? ''), 'focus' => strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['p'] ?? ''))), 'phoneHint' => $preview ? '' : '+' . substr($phone, 0, 2) . '•••••' . substr($phone, -3)];
header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex'); header('X-Frame-Options: SAMEORIGIN'); // the dashboard opens this page in a modal
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"); // product photos live on the business's own https hosts
$v = fn(string $f) => (int)@filemtime(ROOT . '/assets/' . $f);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<meta name="theme-color" content="<?= e($b['brand_color']) ?>">
<title><?= e($b['cat_title'] !== '' ? $b['cat_title'] . ' · ' : '') ?><?= e($b['name']) ?></title>
<?php if ($b['has_logo']): ?><link rel="icon" href="<?= e($b['logo_url']) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('assets/css/shop.css')) ?>?v=<?= $v('css/shop.css') ?>">
</head><body>
<div id="app" aria-live="polite"><noscript><p style="padding:24px">Please turn on JavaScript to browse the catalogue.</p></noscript></div>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= e(url('assets/js/shop.js')) ?>?v=<?= $v('js/shop.js') ?>"></script>
</body></html>
