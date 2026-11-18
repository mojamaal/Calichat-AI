<?php
/**
 * MANUAL live test (real GEMINI_API_KEY, Apache running, dev DB migrated). A throw-away tenant with the built-in store. Checks that the AI
 * pulls order details out of free-form messages, asks once for what is missing, validates with check_order, remembers the customer,
 * and creates/updates the order correctly.   php tests/e2e/extract_e2e.php
 */
require dirname(__DIR__, 2) . '/config/bootstrap.php';
use CaliChat\Core\{Db, Tenant};
use CaliChat\Services\{Chat, Store};

$tid = Db::insert('tenants', ['name' => 'Extract E2E', 'slug' => 'extract-e2e-' . rand_key(3), 'settings' => '{}']);
$uid = Db::insert('users', ['tenant_id' => $tid, 'name' => 'E2E Owner', 'email' => 'e2e-' . rand_key(3) . '@test.local', 'password_hash' => 'x', 'role' => 'owner']);
Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Kavi', 'business_name' => 'Extract E2E', 'temperature' => 1.0, 'tone' => 'friendly',
    'instructions' => "We are an online fashion store in Sri Lanka. Prices are in LKR. Take orders by chat. Delivery island-wide."]);
Store::seedSample($tid); Store::connect($tid);
$tenant = Tenant::get($tid); $user = ['id' => $uid, 'name' => 'E2E Owner'];
$results = []; $check = function (string $name, bool $pass, string $detail = '') use (&$results) { $results[] = [$name, $pass]; echo '  ' . ($pass ? 'PASS' : 'FAIL') . "  $name" . ($detail ? "  ($detail)" : '') . "\n"; };
$say = function (string $phone, string $text) use ($tenant, $user) {
    $r = Chat::playground($tenant, $user, $text, $phone, true);
    printf("CUSTOMER: %s\n  AI [%s]: %s\n%s\n", $text, implode(',', array_column($r['tools'], 'name')), str_replace("\n", "\n      ", (string)$r['reply']), $r['error'] ? "  ERROR: {$r['error']}\n" : '');
    return $r;
};
$orders = fn($phone) => Db::all("SELECT o.*, (SELECT GROUP_CONCAT(CONCAT(i.qty,'x',i.sku,'/',COALESCE(i.size,'-'),'/',COALESCE(i.color,'-')) ORDER BY i.id SEPARATOR ' ') FROM store_order_items i WHERE i.order_id=o.id) item_lines FROM store_orders o WHERE o.tenant_id=? AND o.phone=? ORDER BY o.id", [$tid, $phone]);
$tools = fn($r) => array_column($r['tools'], 'name');

echo "== 1. Everything in one message (English)\n"; $p1 = '94771110001';
$r = $say($p1, "Hi! I need 2 black baseball caps and 1 medium navy polo shirt. Please deliver to 45 Temple Road, Kandy. My name is Nimal Perera and I'll pay cash on delivery.");
$check('searched the catalogue', in_array('search_products', $tools($r), true)); $check('validated with check_order', in_array('check_order', $tools($r), true)); $check('showed a preview (create_order called)', in_array('create_order', $tools($r), true));
$check('did NOT ask again for details already given', !preg_match('/(what|which).{0,40}(address|name|payment)/i', (string)$r['reply']));
$check('total is correct (2x1500 + 2650 + 450 delivery = 6100)', strpos(str_replace(',', '', (string)$r['reply']), '6100') !== false, 'reply mentions 6,100');
$say($p1, "Yes, please place it.");
$o = $orders($p1); $check('order created', count($o) === 1); if ($o) { $check('items extracted correctly', $o[0]['item_lines'] === '2xAC-503/-/Black 1xTS-002/M/Navy', (string)$o[0]['item_lines']); $check('address and town extracted', $o[0]['delivery_city'] === 'Kandy' && stripos((string)$o[0]['delivery_address'], '45 Temple') !== false); $check('name and payment extracted', stripos((string)$o[0]['customer_name'], 'Nimal') !== false && $o[0]['payment_method'] === 'Cash on delivery'); }
$prof = Store::profile($tid, $p1); $check('details remembered for next time', $prof && stripos((string)$prof['address'], '45 Temple') !== false && $prof['city'] === 'Kandy');

echo "\n== 2. Repeat customer (new chat): uses what it knows, asks only for what is new\n";
Db::q("UPDATE conversations SET status='closed' WHERE tenant_id=? AND contact_id IN (SELECT id FROM contacts WHERE tenant_id=? AND external_id LIKE ?)", [$tid, $tid, "%$p1"]);
$r = $say($p1, "Hi, same as last time but one khaki baseball cap this time, pay by bank transfer.");
$check('offers/uses the saved address instead of asking for it', stripos((string)$r['reply'], 'Temple') !== false || stripos((string)$r['reply'], 'Kandy') !== false);
$check('does not ask for the name again', !preg_match('/your name/i', (string)$r['reply']));
$say($p1, "Yes, that address is right. Go ahead.");
$o = $orders($p1); $check('second order created with the khaki cap and bank transfer', count($o) === 2 && $o[1]['item_lines'] === '1xAC-503/-/Khaki' && $o[1]['payment_method'] === 'Bank transfer', $o ? (string)end($o)['item_lines'] : 'none');

echo "\n== 3. Singlish, details spread across messages\n"; $p3 = '94771110003';
$r = $say($p3, "mata white t shirt ekak ona M size");
$check('asks for the missing details together', (bool)preg_match('/(address|town|city|ලිපිනය|නගරය|mukalam|ලිපිනය)/iu', (string)$r['reply']));
$say($p3, "Colombo 5, 23 Havelock Road. Kamal. COD");
$say($p3, "ow, confirm");
$o = $orders($p3); $check('Singlish order created', count($o) === 1, $o ? (string)$o[0]['item_lines'] : 'none'); if ($o) $check('white M T-shirt, Colombo, COD', $o[0]['item_lines'] === '1xTS-001/M/White' && stripos((string)$o[0]['delivery_city'], 'Colombo') === 0 && stripos((string)$o[0]['delivery_address'], 'Havelock') !== false, (string)$o[0]['item_lines']);

echo "\n== 4. Changing an order\n";
$no = $o ? $o[0]['order_no'] : '';
$r = $say($p3, "Actually can you make that shirt size L instead? And deliver to 45 Park Street, Colombo 2.");
$say($p3, "Yes please update it.");
$o = $orders($p3); $check('order updated (size L, new address)', $o && $o[0]['item_lines'] === '1xTS-001/L/White' && stripos((string)$o[0]['delivery_address'], 'Park Street') !== false, $o ? $o[0]['item_lines'] . ' | ' . $o[0]['delivery_address'] : 'none');

$fail = count(array_filter($results, fn($r) => !$r[1])); echo "\n" . (count($results) - $fail) . " passed, $fail failed\n";
if (getenv('KEEP')) echo "(kept tenant $tid)\n"; else Db::q("DELETE FROM tenants WHERE id=?", [$tid]);
exit($fail ? 1 : 0);
