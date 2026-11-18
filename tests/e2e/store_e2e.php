<?php
/**
 * MANUAL live test (needs a real GEMINI_API_KEY and the dev database migrated). Creates a throw-away tenant "Store E2E" with the built-in store,
 * lets the real Gemini agent take an order, change it, cancel it and record a lead through the playground (write actions on), prints what happened
 * in the store, then deletes the tenant. Apache must be running (the AI's tools call /store/v1 over HTTP).  php tests/e2e/store_e2e.php
 */
require dirname(__DIR__, 2) . '/config/bootstrap.php';
use CaliChat\Core\{Db, Tenant};
use CaliChat\Services\{Chat, Store};

$tid = Db::insert('tenants', ['name' => 'Store E2E', 'slug' => 'store-e2e-' . rand_key(3), 'settings' => '{}']);
$uid = Db::insert('users', ['tenant_id' => $tid, 'name' => 'E2E Owner', 'email' => 'e2e-' . rand_key(3) . '@test.local', 'password_hash' => 'x', 'role' => 'owner']);
Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Kavi', 'business_name' => 'Store E2E', 'temperature' => 1.0, 'tone' => 'friendly',
    'instructions' => "We are an online fashion store in Sri Lanka. Prices are in LKR. Use search_products for products and stock, delivery_info for delivery fees. To place an order collect items (with size where needed), delivery city, address and payment method, then use create_order. To change or cancel an order use update_order / cancel_order. For bulk or corporate enquiries use create_lead."]);
Store::seedSample($tid); Store::connect($tid);
$tenant = Tenant::get($tid); $user = ['id' => $uid, 'name' => 'E2E Owner']; $phone = '94771234567';
$say = function (string $text) use ($tenant, $user, $phone) {
    $r = Chat::playground($tenant, $user, $text, $phone, true);
    printf("CUSTOMER: %s\n  AI [%s]: %s\n%s\n", $text, implode(',', array_column($r['tools'], 'name')), str_replace("\n", "\n      ", (string)$r['reply']), $r['error'] ? "  ERROR: {$r['error']}\n" : '');
};
$say("Hi, do you have a black baseball cap? How much, and how much is delivery to Kandy?");
$say("Great. I'd like 2 of them, deliver to 45 Temple Road, Kandy. My name is Nimal. Cash on delivery please.");
$say("Yes, please go ahead and place the order.");
$say("Actually, can you change the delivery address to 12 Lake Road, Galle?");
$say("Yes, that's correct.");
$say("Sorry, I changed my mind. Please cancel that order.");
$say("Yes, cancel it.");
$say("I also need 30 polo shirts for my company. Can someone call me on weekday evenings? Name is Nimal.");
$say("Yes, please record that.");
echo "---- store state ----\n";
foreach (Db::all("SELECT order_no, status, source, delivery_city, delivery_address, total FROM store_orders WHERE tenant_id=?", [$tid]) as $o) echo json_encode($o), "\n";
foreach (Db::all("SELECT id, name, interest, preferred_time, stage, source FROM crm_leads WHERE tenant_id=?", [$tid]) as $l) echo json_encode($l), "\n";
echo 'Cap stock now (started at 50): ', Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku='AC-503'", [$tid]), "\n";
if (getenv('KEEP')) echo "(kept tenant $tid)\n"; else { Db::q("DELETE FROM tenants WHERE id=?", [$tid]); echo "(throw-away tenant deleted)\n"; }
