<?php
declare(strict_types=1);
/**
 * Phase 1 tests: built-in store + CRM, WhatsApp templates, follow-up automations.
 * Included by tests/run.php after the HTTP server and the Graph stub are running; shares its helpers (t, ok, eq, throws, mkTenant, Http, $h, $stubLog).
 */
use CaliChat\Core\{Crypto, Db, Tenant};
use CaliChat\Services\{Automations, Chat, Store, StoreError, Templates, ToolRunner};

echo "\nPhase 1: built-in store, CRM, WhatsApp templates, follow-ups\n";
$waChannel = function (int $tid, string $ext = '1055', ?string $waba = '2066') {
    return Db::insert('channels', ['tenant_id' => $tid, 'type' => 'whatsapp', 'name' => 'WA ' . $ext, 'external_id' => $ext, 'access_token' => Crypto::encrypt('TOKEN-' . $ext), 'waba_id' => $waba, 'verify_token' => 'v', 'channel_key' => rand_key(10)]);
};
$code = function (callable $f): int { try { $f(); } catch (StoreError $e) { return (int)$e->getCode(); } return 0; };
$stubFile = sys_get_temp_dir() . '/cc_graph_stub.log';

t('store: catalogue validation, CSV import (upsert, row errors) and tenant scoping', function () {
    $a = mkTenant('StA'); $b = mkTenant('StB');
    foreach ([['sku' => 'x', 'name' => 'n', 'price' => 1], ['sku' => 'AB-1', 'name' => '', 'price' => 1], ['sku' => 'AB-1', 'name' => 'n', 'price' => -5], ['sku' => 'AB-1', 'name' => 'n', 'price' => 5, 'image_url' => 'http://insecure.test/x.png'], ['sku' => 'AB-1', 'name' => 'n', 'price' => 5, 'variants' => 'S,<b>']] as $bad) throws(fn() => Store::saveProduct($a['tid'], $bad), 'must reject ' . json_encode($bad));
    $id = Store::saveProduct($a['tid'], ['sku' => 'ab-1', 'name' => 'Alpha', 'price' => 100, 'stock' => 3, 'variants' => 'S, M']);
    eq(Db::val("SELECT sku FROM store_products WHERE id=?", [$id]), 'AB-1', 'SKU normalised'); eq(Db::val("SELECT variants FROM store_products WHERE id=?", [$id]), 'S,M');
    $r = Store::importCsv($a['tid'], "sku,name,price,stock\nCS-1,One,10,5\nCS-2,Two,-1,1\nAB-1,Alpha v2,120,9\n");
    eq($r['added'], 2); eq(count($r['errors']), 1); ok(strpos($r['errors'][0], 'Row 3') !== false, 'error names the row'); eq((float)Db::val("SELECT price FROM store_products WHERE id=?", [$id]), 120.0, 'existing SKU updated');
    eq(Store::importCsv($a['tid'], "name,price\nx,1")['added'], 0, 'missing sku column refused');
    eq((int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=?", [$b['tid']]), 0, 'other tenant sees nothing');
    Store::saveProduct($b['tid'], ['sku' => 'AB-1', 'name' => 'Beta own', 'price' => 7]); eq((int)Db::val("SELECT COUNT(*) FROM store_products WHERE sku='AB-1'"), 2, 'same SKU may exist in two tenants');
});
t('store: orders price from the catalogue, quote delivery by town, reserve and release stock, enforce the life cycle', function () use ($code) {
    $v = mkTenant('StO'); $tid = $v['tid']; Store::seedSample($tid);
    $stock = fn($sku) => (int)Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku=?", [$tid, $sku]); $s0 = $stock('AC-503'); $t0 = $stock('TS-001');
    $mk = fn($items, $city = 'colombo', $pay = 'Cash on delivery', $src = 'manual') => Store::createOrder($tid, ['phone' => '94770000001', 'customer_name' => 'T', 'items' => $items, 'delivery_city' => $city, 'delivery_address' => '1 Test Rd', 'payment_method' => $pay], $src);
    $o = $mk('AC-503:-:2:Black,TS-001:M:1:Black'); eq((float)$o['subtotal'], 4850.0, 'prices come from the catalogue'); eq((float)$o['delivery_fee'], 350.0, 'Colombo zone fee, case-insensitive'); eq((float)$o['total'], 5200.0); eq($o['order_no'], 'ORD-1001'); eq($stock('AC-503'), $s0 - 2, 'stock reserved');
    $o2 = $mk('WT-700:-:1:Silver', 'Kandy'); eq((float)$o2['delivery_fee'], 0.0, 'free delivery over the threshold'); eq($o2['order_no'], 'ORD-1002');
    eq($code(fn() => $mk('NOPE-1:-:1')), 404, 'unknown product'); eq($code(fn() => $mk('TS-001:-:1:Black')), 422, 'size required'); eq($code(fn() => $mk('SC-050:-:1:Red')), 409, 'out of stock'); eq($code(fn() => $mk('AC-503:-:1:Black', 'Colombo', 'Gold coins')), 422, 'unsupported payment');
    eq((int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=?", [$tid]), 2, 'failed orders leave nothing behind');
    Db::q("UPDATE store_zones SET cod_allowed=0 WHERE tenant_id=? AND town='Galle'", [$tid]); eq($code(fn() => $mk('AC-503:-:1:Black', 'Galle')), 422, 'COD not allowed in that town'); ok($mk('AC-503:-:1:Black', 'Galle', 'Bank transfer')['id'] > 0);
    Tenant::saveSettings($tid, ['store_max_total' => 1000]); eq($code(fn() => $mk('TS-001:M:1:Black', 'Colombo', 'Cash on delivery', 'ai')), 422, 'AI orders above the limit are refused'); ok($mk('TS-001:M:1:Black')['id'] > 0, 'staff may exceed it'); Tenant::saveSettings($tid, ['store_max_total' => 100000]);
    $u = Store::updateOrder($tid, $o, ['items' => 'AC-503:-:1:Black']); eq((float)$u['subtotal'], 1500.0); eq($stock('AC-503'), $s0 - 2, 'caps: 1 on this order + 1 on the Galle order'); eq($stock('TS-001'), $t0 - 1, 'the T-shirt on this order went back; only the staff order holds one');
    $u = Store::updateOrder($tid, $u, ['delivery_city' => 'Jaffna']); eq((float)$u['delivery_fee'], 650.0, 'changing town re-quotes delivery'); eq((float)$u['total'], 2150.0);
    eq($code(fn() => Store::setStatus($tid, $o2, 'delivered')), 409, 'cannot skip packing and shipping');
    $o2 = Store::setStatus($tid, $o2, 'packed'); $o2 = Store::setStatus($tid, $o2, 'shipped', ['courier' => 'Domex', 'tracking' => 'DX1']); eq($o2['courier'], 'Domex'); ok($o2['shipped_at'] !== null);
    eq($code(fn() => Store::updateOrder($tid, $o2, ['delivery_city' => 'Kandy'])), 409, 'shipped orders cannot be edited'); eq($code(fn() => Store::setStatus($tid, $o2, 'cancelled')), 409, 'shipped orders cannot be cancelled');
    $o2 = Store::setStatus($tid, $o2, 'delivered'); eq($o2['payment_status'], 'paid', 'cash on delivery is paid on delivery'); ok($o2['delivered_at'] !== null);
    $before = $stock('AC-503'); $c = Store::setStatus($tid, $u, 'cancelled', ['reason' => 'changed mind']); eq($c['status'], 'cancelled'); eq($stock('AC-503'), $before + 1, 'cancel releases stock'); ok(strpos((string)$c['notes'], 'changed mind') !== false);
    eq($code(fn() => Store::setStatus($tid, $c, 'packed')), 409, 'cancelled is final');
});
t('store: leads are de-duplicated, confirmed only by their phone, and moved through stages', function () use ($code) {
    $v = mkTenant('StL'); $tid = $v['tid'];
    $r = Store::createLead($tid, ['phone' => '94771110001', 'name' => 'Ann', 'interest' => '50 caps for a school event'], 'ai'); ok(!$r['duplicate']); eq($r['lead']['stage'], 'new');
    ok(Store::createLead($tid, ['phone' => '94771110001', 'name' => 'Ann', 'interest' => '50 caps for a school event'], 'ai')['duplicate'], 'same enquiry in a day is not duplicated');
    eq($code(fn() => Store::confirmLead($tid, (int)$r['lead']['id'], '94771110002')), 404, "someone else's lead looks like it does not exist");
    $l = Store::confirmLead($tid, (int)$r['lead']['id'], '94771110001'); ok($l['confirmed_at'] !== null); eq($code(fn() => Store::confirmLead($tid, (int)$r['lead']['id'], '94771110001')), 409, 'cannot confirm twice');
    Store::setStage($tid, (int)$r['lead']['id'], 'won'); eq(Db::val("SELECT stage FROM crm_leads WHERE id=?", [$r['lead']['id']]), 'won'); eq($code(fn() => Store::setStage($tid, (int)$r['lead']['id'], 'bogus')), 422); eq($code(fn() => Store::setStage($tid, 99999999, 'won')), 404);
});
t('store API: a token per tenant, tenant taken from the token, orders private to their phone, idempotent', function () use ($h) {
    $a = mkTenant('ApiA'); $b = mkTenant('ApiB'); foreach ([$a, $b] as $x) { Store::seedSample($x['tid']); Store::connect($x['tid']); }
    $tok = fn($tid) => Crypto::decrypt(Db::val("SELECT auth_secret FROM connectors WHERE tenant_id=? AND name=?", [$tid, Store::CONNECTOR]));
    $call = function ($m, $p, $tk, $body = null, $x = []) use ($h) { $r = $h->req($m, "/store/v1/$p", $body === null ? null : json_encode($body), array_merge(['Authorization: Bearer ' . $tk, 'Content-Type: application/json'], $x)); $r['json'] = json_decode($r['body'], true); return $r; };
    eq($h->req('GET', '/store/v1/products')['code'], 401); eq($call('GET', 'products', 'st_wrong')['code'], 401); eq($call('GET', 'products', 'demo-erp-token')['code'], 401, 'the demo ERP token does not open a real store');
    ok($tok($a['tid']) !== $tok($b['tid']), 'each tenant has its own token'); $ta = $tok($a['tid']); $tb = $tok($b['tid']);
    $r = $call('GET', 'products?search=jeans', $ta); eq($r['code'], 200); eq($r['json']['data'][0]['sku'], 'JN-015');
    Db::q("UPDATE store_products SET price=1, is_active=0 WHERE tenant_id=? AND sku='JN-015'", [$b['tid']]); eq($call('GET', 'products?search=jeans', $tb)['json']['data'], [], 'tenant B hid the product'); eq((float)$call('GET', 'products/JN-015', $ta)['json']['data']['price'], 5490.0, 'tenant A is unaffected');
    eq((float)$call('GET', 'delivery?city=Jaffna&subtotal=2000', $ta)['json']['data']['fee'], 650.0);
    $body = ['phone' => '94770009001', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '5 Lake Rd', 'payment_method' => 'Cash on delivery', 'customer_name' => 'N'];
    $r1 = $call('POST', 'orders', $ta, $body, ['Idempotency-Key: k-1']); eq($r1['code'], 201); $no = $r1['json']['data']['order_no']; eq((float)$r1['json']['data']['total'], 1850.0);
    $r2 = $call('POST', 'orders', $ta, $body, ['Idempotency-Key: k-1']); ok(!empty($r2['json']['idempotent_replay']), 'a repeated request returns the first answer'); eq((int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=? AND phone='94770009001'", [$a['tid']]), 1);
    eq($call('GET', "orders/$no?phone=94770009999", $ta)['code'], 404, "another customer's phone cannot see it"); eq($call('GET', "orders/$no?phone=94770009001", $ta)['code'], 200); eq($call('GET', "orders/$no?phone=94770009001", $tb)['code'], 404, 'another tenant cannot see it');
    eq($call('POST', "orders/$no/cancel", $ta, ['phone' => '94770009999'])['code'], 404); $s = (int)Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku='AC-503'", [$a['tid']]);
    $c = $call('POST', "orders/$no/cancel", $ta, ['phone' => '94770009001', 'reason' => 'test']); eq($c['code'], 200); eq($c['json']['data']['status'], 'Cancelled'); eq((int)Db::val("SELECT stock FROM store_products WHERE tenant_id=? AND sku='AC-503'", [$a['tid']]), $s + 1);
    eq($call('POST', "orders/$no/cancel", $ta, ['phone' => '94770009001'])['code'], 409, 'cannot cancel twice');
    $l = $call('POST', 'leads', $ta, ['phone' => '94770009001', 'name' => 'N', 'interest' => 'Bulk order']); eq($l['code'], 201); $lid = $l['json']['data']['id']; ok(!empty($call('POST', 'leads', $ta, ['phone' => '94770009001', 'name' => 'N', 'interest' => 'Bulk order'])['json']['duplicate']));
    eq($call('POST', "leads/$lid/confirm", $ta, ['phone' => '94770009002'])['code'], 404); eq($call('POST', "leads/$lid/confirm", $ta, ['phone' => '94770009001'])['code'], 200); eq($call('POST', "leads/$lid/confirm", $ta, ['phone' => '94770009001'])['code'], 409);
    Db::update('tenants', ['status' => 'suspended'], 'id=?', [$b['tid']]); eq($call('GET', 'products', $tb)['code'], 401, 'a suspended tenant token stops working');
});
t('store: the AI places, changes and cancels orders and records leads through the built-in store (preview, reply, confirm)', function () {
    $v = mkTenant('StAi'); $tid = $v['tid']; Store::seedSample($tid); Store::connect($tid); $phone = '94700444111';
    $c = Chat::contact($tid, 0, 'whatsapp', 'ai1', 'Ai Tester', $phone); $conv = Chat::conversation($tid, null, 'whatsapp', $c, null); $cid = (int)$conv['id'];
    $tool = fn($n) => Db::one("SELECT * FROM tools WHERE tenant_id=? AND name=?", [$tid, $n]); $run = fn($n, $a) => ToolRunner::run($tool($n), $a, $c, $cid); $say = fn($m) => Chat::addMessage($tid, $cid, 'in', 'customer', $m);
    $go = function ($n, $a) use ($run, $say) { $say('please do it'); $run($n, $a); $say('yes confirm'); return $run($n, $a + ['customer_confirmed' => true]); };
    $r = $run('search_products', ['search' => 'cap']); ok($r['ok'], (string)$r['error']); ok(strpos(json_encode($r['data']), 'AC-503') !== false, 'finds the cap'); ok(strpos(json_encode($r['data']), 'cost_price') === false, 'private cost price never reaches the AI');
    $r = $run('delivery_info', ['city' => 'Kandy', 'subtotal' => 2000]); ok($r['ok'], (string)$r['error']); ok(strpos(json_encode($r['data']), '450') !== false, 'delivery fee quoted from the store');
    $args = ['items' => 'AC-503:-:2:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '12 Galle Road', 'payment_method' => 'Cash on delivery'];
    $say('I want 2 caps'); $p = $run('create_order', $args); ok($p['ok'] && $p['data']['status'] === 'confirmation_required', 'first call is only a preview'); eq((int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=?", [$tid]), 0);
    $say('yes'); $r = $run('create_order', $args + ['customer_confirmed' => true]); ok($r['ok'], (string)$r['error']); $no = $r['data']['data']['order_no']; eq((float)$r['data']['data']['total'], 3350.0); eq(Db::val("SELECT source FROM store_orders WHERE order_no=? AND tenant_id=?", [$no, $tid]), 'ai');
    $r = $go('update_order', ['order_no' => $no, 'delivery_address' => '99 Lake Road']); ok($r['ok'], (string)$r['error']); eq(Db::val("SELECT delivery_address FROM store_orders WHERE order_no=? AND tenant_id=?", [$no, $tid]), '99 Lake Road');
    $r = $run('list_my_orders', []); ok($r['ok'] && strpos(json_encode($r['data']), $no) !== false, "lists the customer's own order");
    $r = $go('cancel_order', ['order_no' => $no, 'reason' => 'changed mind']); ok($r['ok'], (string)$r['error']); eq(Db::val("SELECT status FROM store_orders WHERE order_no=? AND tenant_id=?", [$no, $tid]), 'cancelled');
    $r = $go('create_lead', ['name' => 'Sam', 'interest' => 'Bulk 50 caps']); ok($r['ok'], (string)$r['error']); $lid = $r['data']['data']['id']; $r = $go('confirm_lead', ['lead_id' => (string)$lid]); ok($r['ok'], (string)$r['error']); ok(Db::val("SELECT confirmed_at FROM crm_leads WHERE id=?", [$lid]) !== null);
    $other = mkTenant('StAi2'); Store::seedSample($other['tid']); Store::connect($other['tid']);
    $oc = Chat::contact($other['tid'], 0, 'whatsapp', 'ai2', 'X', $phone); $ov = Chat::conversation($other['tid'], null, 'whatsapp', $oc, null); $ot = Db::one("SELECT * FROM tools WHERE tenant_id=? AND name='list_my_orders'", [$other['tid']]);
    $r = ToolRunner::run($ot, [], $oc, (int)$ov['id']); ok($r['ok'] && strpos(json_encode($r['data']), $no) === false, 'the same phone number in another business sees nothing');
});

t('templates: placeholders become Meta variables and Meta rules are checked before submitting', function () {
    eq(Templates::variables('Hi {name}, {order_no} and {name}'), ['name', 'order_no']); eq(Templates::toMeta('Hi {name}, order {order_no}, bye {name}!'), 'Hi {{1}}, order {{2}}, bye {{1}}!');
    $ok = ['name' => 'order_shipped', 'language' => 'en', 'category' => 'utility', 'body' => 'Hi {name}, your order {order_no} has shipped with {courier}. Thank you for shopping with us.', 'buttons' => null];
    eq(Templates::problems($ok)['errors'], [], 'a good template has no errors');
    $bad = fn(array $o) => count(Templates::problems($o + $ok)['errors']) > 0;
    ok($bad(['name' => 'Order Shipped']), 'name format'); ok($bad(['body' => '{name}, your order is on its way and we thank you']), 'starts with a placeholder'); ok($bad(['body' => 'Your order is on its way, thank you {name}']), 'ends with a placeholder');
    ok($bad(['body' => 'Hi {{1}}, your order is on the way']), 'raw Meta syntax'); ok($bad(['body' => 'Hi {nope}, your order is on the way']), 'unknown placeholder'); ok($bad(['header_text' => 'Hi {name}']), 'placeholder in header');
    ok($bad(['buttons' => json_encode(['a', 'b', 'c', 'd'])]), 'more than 3 buttons'); ok($bad(['body' => str_repeat('x ', 600)]), 'body too long'); ok($bad(['body' => "Hi {name}\n\n\n\nyour order is on its way"]), 'too many blank lines'); ok($bad(['body' => '']), 'empty');
    ok(count(Templates::problems(['category' => 'marketing', 'footer' => null] + $ok)['warnings']) >= 1, 'marketing without opt-out wording warns'); eq(Templates::problems(['category' => 'marketing', 'footer' => 'Reply STOP to unsubscribe'] + $ok)['warnings'], []);
    foreach (Templates::starters() as [$n, $cat, $body, $footer, $buttons]) eq(Templates::problems(['name' => $n, 'language' => 'en', 'category' => $cat, 'body' => $body, 'footer' => $footer, 'buttons' => $buttons])['errors'], [], "starter $n passes the checks");
    eq(Templates::values(['body' => 'a {name} b {order_no}'], ['name' => 'Kamal']), ['Kamal', '-'], 'missing values never send an empty parameter');
});
t('templates: submit to Meta, approval and rejection come back through sync, no edits after submission', function () use ($waChannel) {
    $stubLog = fn() => is_file(sys_get_temp_dir() . '/cc_graph_stub.log') ? array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_graph_stub.log', FILE_IGNORE_NEW_LINES)) : [];
    @unlink(sys_get_temp_dir() . '/cc_stub_templates.json'); @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
    $v = mkTenant('Tpl'); $tid = $v['tid']; $ch = $waChannel($tid, '7001', null);
    eq(Automations::installStarterTemplates($tid), 9); eq(Automations::installStarterTemplates($tid), 0, 'starters are added once');
    $id = (int)Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name='order_shipped'", [$tid]);
    $r = Templates::submit($tid, $id); ok(!$r['ok'] && stripos($r['error'], 'Business Account') !== false, 'needs the business account id first: ' . $r['error']);
    Db::update('channels', ['waba_id' => '2066'], 'id=?', [$ch]);
    $r = Templates::submit($tid, $id); ok($r['ok'], (string)$r['error']); $row = Db::one("SELECT * FROM wa_templates WHERE id=?", [$id]); eq($row['status'], 'pending'); ok($row['meta_id'] !== null && (int)$row['channel_id'] === $ch);
    $post = array_values(array_filter($stubLog(), fn($l) => $l['m'] === 'POST' && $l['p'] === '/2066/message_templates')); eq(count($post), 1); $j = $post[0]['j']; eq($j['category'], 'UTILITY'); eq($j['name'], 'order_shipped');
    $body = array_values(array_filter($j['components'], fn($c) => $c['type'] === 'BODY'))[0]; ok(strpos($body['text'], '{{1}}') !== false && strpos($body['text'], '{name}') === false, 'readable placeholders were converted'); eq(count($body['example']['body_text'][0]), 5, 'one sample value per variable'); eq($post[0]['auth'], 'Bearer TOKEN-7001');
    $again = Templates::submit($tid, $id); ok(!$again['ok'] && stripos($again['error'], 'already submitted') !== false);
    Db::insert('wa_templates', ['tenant_id' => $tid, 'name' => 'bad_one', 'language' => 'en', 'category' => 'utility', 'body' => 'Hi {name}, your order {order_no} is ready for you today.', 'status' => 'draft']);
    ok(Templates::submit($tid, (int)Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name='bad_one'", [$tid]))['ok'], 'Meta may reject right away; submit itself succeeds');
    $f = sys_get_temp_dir() . '/cc_stub_templates.json'; $all = json_decode((string)file_get_contents($f), true); foreach ($all as &$t) if ($t['name'] === 'order_shipped') $t['status'] = 'APPROVED'; unset($t); file_put_contents($f, json_encode($all));
    $s = Templates::sync($tid); ok($s['ok'], (string)$s['error']); eq(Db::val("SELECT status FROM wa_templates WHERE id=?", [$id]), 'approved'); $bad = Db::one("SELECT * FROM wa_templates WHERE tenant_id=? AND name='bad_one'", [$tid]); eq($bad['status'], 'rejected'); eq($bad['rejection_reason'], 'INVALID_FORMAT', 'the real reason replaces the placeholder text');
    eq((int)Db::val("SELECT COUNT(*) FROM alerts WHERE tenant_id=? AND type='template_rejected'", [$tid]), 1, 'the team is told about a rejection'); eq(Templates::sync($tid)['updated'], 0, 'a second sync changes nothing');
    ok(Templates::approved($tid, 'order_shipped', 'en') !== null && Templates::approved($tid, 'bad_one', 'en') === null);
});

t('follow-ups: shipped and confirmation messages go out inside the 24h window, are recorded in the inbox, and are logged', function () use ($waChannel) {
    $stubLog = fn() => is_file(sys_get_temp_dir() . '/cc_graph_stub.log') ? array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_graph_stub.log', FILE_IGNORE_NEW_LINES)) : [];
    @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
    $v = mkTenant('Fu1'); $tid = $v['tid']; Store::seedSample($tid); $ch = $waChannel($tid, '7101'); Tenant::saveSettings($tid, ['quiet_start' => '00:00', 'quiet_end' => '00:00']);
    ok(Automations::installRecommended($tid) >= 7); eq(Automations::installRecommended($tid), 0, 'recommended set is added once');
    $c = Chat::contact($tid, $ch, 'whatsapp', '94700555001', 'Kamal', '94700555001'); $conv = Chat::conversation($tid, Db::one("SELECT * FROM channels WHERE id=?", [$ch]), 'whatsapp', $c, null); Chat::addMessage($tid, (int)$conv['id'], 'in', 'customer', 'hi');
    $o = Store::createOrder($tid, ['phone' => '94700555001', 'customer_name' => 'Kamal', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd', 'payment_method' => 'Cash on delivery'], 'manual');
    Store::createOrder($tid, ['phone' => '94700555001', 'customer_name' => 'Kamal', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'ai');
    $o = Store::setStatus($tid, $o, 'packed'); $o = Store::setStatus($tid, $o, 'shipped', ['courier' => 'Domex', 'tracking' => 'DX99']);
    eq((int)Db::val("SELECT COUNT(*) FROM automation_runs WHERE tenant_id=? AND status='scheduled'", [$tid]), 3, 'confirmation (staff order), shipped, and the AI order confirmation');
    $r = Automations::tick(); eq($r['sent'], 2, json_encode($r)); eq($r['skipped'], 1, 'the AI-placed order is not confirmed again: the customer already confirmed it in chat');
    ok(strpos((string)Db::val("SELECT reason FROM automation_runs WHERE tenant_id=? AND status='skipped'", [$tid]), 'AI') !== false, 'skip reason is stored and readable');
    $msgs = Db::all("SELECT content, meta FROM messages WHERE conversation_id=? AND sender='ai' ORDER BY id", [$conv['id']]); eq(count($msgs), 2); ok(strpos($msgs[0]['content'], $o['order_no']) !== false, 'confirmation names the order'); ok(strpos($msgs[1]['content'], 'Domex') !== false && strpos($msgs[1]['content'], 'DX99') !== false, 'shipped message carries courier and tracking'); ok(strpos((string)$msgs[1]['meta'], 'automation') !== false);
    $sent = array_values(array_filter($stubLog(), fn($l) => $l['p'] === '/7101/messages')); eq(count($sent), 2, 'two WhatsApp messages left the system'); eq($sent[1]['j']['to'], '94700555001');
    eq(Automations::tick()['sent'], 0, 'nothing is sent twice'); Automations::emit($tid, 'order_shipped', 'order', (int)$o['id']); eq((int)Db::val("SELECT COUNT(*) FROM automation_runs WHERE tenant_id=? AND subject_id=?", [$tid, $o['id']]), 2, 'an event is scheduled once per follow-up (placed + shipped)');
});
t('follow-ups: outside 24h an approved template is required, and it is filled from the order', function () use ($waChannel) {
    $stubLog = fn() => is_file(sys_get_temp_dir() . '/cc_graph_stub.log') ? array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_graph_stub.log', FILE_IGNORE_NEW_LINES)) : [];
    @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
    $v = mkTenant('Fu2'); $tid = $v['tid']; Store::seedSample($tid); $ch = $waChannel($tid, '7102'); Tenant::saveSettings($tid, ['quiet_start' => '00:00', 'quiet_end' => '00:00']); Automations::installRecommended($tid);
    $c = Chat::contact($tid, $ch, 'whatsapp', '94700555002', 'Nuwan', '94700555002'); $conv = Chat::conversation($tid, Db::one("SELECT * FROM channels WHERE id=?", [$ch]), 'whatsapp', $c, null); Chat::addMessage($tid, (int)$conv['id'], 'in', 'customer', 'hi'); Db::q("UPDATE conversations SET last_customer_at = NOW() - INTERVAL 2 DAY WHERE id=?", [$conv['id']]);
    $new = function () use ($tid) { $o = Store::createOrder($tid, ['phone' => '94700555002', 'customer_name' => 'Nuwan', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'manual'); $o = Store::setStatus($tid, $o, 'packed'); return Store::setStatus($tid, $o, 'shipped', ['courier' => 'Pronto', 'tracking' => 'PR7']); };
    $shipReason = fn($o) => (string)Db::val("SELECT reason FROM automation_runs WHERE tenant_id=? AND automation_id=(SELECT id FROM automations WHERE tenant_id=? AND trigger_type='order_shipped') AND subject_id=?", [$tid, $tid, $o['id']]);
    $o1 = $new(); $r = Automations::tick(); eq($r['sent'], 0); ok(strpos($shipReason($o1), '24-hour') !== false, 'skipped with a clear reason: ' . $shipReason($o1));
    Db::insert('wa_templates', ['tenant_id' => $tid, 'channel_id' => $ch, 'name' => 'order_shipped', 'language' => 'en', 'category' => 'utility', 'body' => 'Hi {name}, your order {order_no} shipped with {courier}. Tracking {tracking}. Thanks for shopping with us.', 'status' => 'pending']);
    $tplId = (int)Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name='order_shipped'", [$tid]); Db::update('automations', ['template_id' => $tplId], "tenant_id=? AND trigger_type='order_shipped'", [$tid]);
    $o2 = $new(); Automations::tick(); ok(strpos($shipReason($o2), 'not approved') !== false, 'a pending template is not used: ' . $shipReason($o2));
    Db::update('wa_templates', ['status' => 'approved'], 'id=?', [$tplId]); $o3 = $new(); $r = Automations::tick(); ok($r['sent'] >= 1, json_encode($r));
    $sent = array_values(array_filter($stubLog(), fn($l) => $l['p'] === '/7102/messages')); eq(count($sent), 1); $tp = $sent[0]['j']; eq($tp['type'], 'template'); eq($tp['template']['name'], 'order_shipped');
    $vals = array_column($tp['template']['components'][0]['parameters'], 'text'); eq($vals, ['Nuwan', $o3['order_no'], 'Pronto', 'PR7'], "values are filled in Meta's order");
});
t('follow-ups: marketing messages need opt-in, STOP is respected, quiet hours delay sending, stale state is re-checked', function () use ($waChannel) {
    $v = mkTenant('Fu3'); $tid = $v['tid']; Store::seedSample($tid); $ch = $waChannel($tid, '7103'); Automations::installRecommended($tid);
    Db::q("UPDATE automations SET delay_minutes=0 WHERE tenant_id=? AND trigger_type IN ('review_request','order_shipped')", [$tid]); Tenant::saveSettings($tid, ['quiet_start' => '00:00', 'quiet_end' => '00:00']);
    $c = Chat::contact($tid, $ch, 'whatsapp', '94700555003', 'Dilani', '94700555003'); $conv = Chat::conversation($tid, Db::one("SELECT * FROM channels WHERE id=?", [$ch]), 'whatsapp', $c, null); Chat::addMessage($tid, (int)$conv['id'], 'in', 'customer', 'hello');
    $deliver = function () use ($tid) { $o = Store::createOrder($tid, ['phone' => '94700555003', 'customer_name' => 'Dilani', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'ai'); foreach (['packed', 'shipped', 'delivered'] as $s) $o = Store::setStatus($tid, $o, $s); return $o; };
    $review = fn($o) => Db::one("SELECT r.* FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE a.trigger_type='review_request' AND r.subject_id=? AND a.tenant_id=?", [$o['id'], $tid]);
    $o = $deliver(); Automations::tick(); $r = $review($o); eq($r['status'], 'skipped'); ok(stripos((string)$r['reason'], 'consent') !== false, 'unknown consent is not enough: ' . $r['reason']);
    Db::update('contacts', ['consent_status' => 'opted_out'], 'id=?', [$c['id']]); $o = $deliver(); Automations::tick(); ok(stripos((string)$review($o)['reason'], 'opted out') !== false, 'opted out');
    Chat::consentKeyword($tid, $c, 'START'); $o = $deliver(); Automations::tick(); eq($review($o)['status'], 'sent', 'opted in customers get the review request');
    Db::update('contacts', ['consent_status' => 'opted_out'], 'id=?', [$c['id']]); $os = Store::createOrder($tid, ['phone' => '94700555003', 'customer_name' => 'Dilani', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'ai'); $os = Store::setStatus($tid, $os, 'packed'); $os = Store::setStatus($tid, $os, 'shipped', ['courier' => 'Domex', 'tracking' => 'D5']); Automations::tick();
    eq(Db::val("SELECT r.status FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE a.trigger_type='order_shipped' AND r.subject_id=? AND a.tenant_id=?", [$os['id'], $tid]), 'sent', 'a customer who sent STOP still gets order updates (transactional), only promotions are blocked'); Chat::consentKeyword($tid, $c, 'START');
    Tenant::saveSettings($tid, ['quiet_start' => '21:00', 'quiet_end' => '08:00']);
    $o = Store::createOrder($tid, ['phone' => '94700555003', 'customer_name' => 'Dilani', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'ai'); $o = Store::setStatus($tid, $o, 'packed'); $o = Store::setStatus($tid, $o, 'shipped', ['courier' => 'X', 'tracking' => 'T']);
    $night = (new DateTime('today 23:00', new DateTimeZone('Asia/Colombo')))->getTimestamp(); $morning = (new DateTime('today 10:00', new DateTimeZone('Asia/Colombo')))->getTimestamp();
    ok(Automations::isQuiet($tid, $night) && !Automations::isQuiet($tid, $morning), 'quiet hours wrap past midnight');
    $r = Automations::tick($night); ok($r['later'] >= 1 && $r['sent'] === 0, 'held during quiet hours: ' . json_encode($r));
    $shipRun = fn($o) => Db::val("SELECT r.status FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE a.trigger_type='order_shipped' AND r.subject_id=? AND a.tenant_id=?", [$o['id'], $tid]); eq($shipRun($o), 'scheduled');
    $r = Automations::tick($morning); ok($r['sent'] >= 1, 'sent after quiet hours: ' . json_encode($r)); eq($shipRun($o), 'sent');
    Tenant::saveSettings($tid, ['quiet_start' => '00:00', 'quiet_end' => '00:00']); Db::update('automations', ['delay_minutes' => 60], 'tenant_id=? AND trigger_type=?', [$tid, 'order_shipped']);
    $o = Store::createOrder($tid, ['phone' => '94700555003', 'customer_name' => 'D', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd'], 'ai'); $o = Store::setStatus($tid, $o, 'packed'); Store::setStatus($tid, $o, 'shipped');
    Db::q("UPDATE store_orders SET status='cancelled' WHERE id=?", [$o['id']]); Db::q("UPDATE automation_runs SET due_at=NOW() WHERE tenant_id=?", [$tid]); Automations::tick();
    ok(stripos((string)Db::val("SELECT r.reason FROM automation_runs r JOIN automations a ON a.id=r.automation_id WHERE a.trigger_type='order_shipped' AND r.subject_id=? AND a.tenant_id=?", [$o['id'], $tid]), 'Cancelled') !== false, 'state is re-checked at send time');
});
t('follow-ups: quiet chats are nudged once, never after the customer writes again; stale leads alert the team', function () use ($waChannel) {
    $v = mkTenant('Fu4'); $tid = $v['tid']; $ch = $waChannel($tid, '7104'); Tenant::saveSettings($tid, ['quiet_start' => '00:00', 'quiet_end' => '00:00']); Automations::installRecommended($tid);
    $channel = Db::one("SELECT * FROM channels WHERE id=?", [$ch]);
    $mkChat = function (string $ext, string $consent, string $status = 'ai') use ($tid, $ch, $channel) {
        $c = Chat::contact($tid, $ch, 'whatsapp', $ext, 'Ruwan', $ext); Db::update('contacts', ['consent_status' => $consent], 'id=?', [$c['id']]); $conv = Chat::conversation($tid, $channel, 'whatsapp', $c, null);
        Chat::addMessage($tid, (int)$conv['id'], 'in', 'customer', 'do you have jeans?'); Db::insert('tool_logs', ['tenant_id' => $tid, 'conversation_id' => $conv['id'], 'tool' => 'search_products', 'request_summary' => 'search_products {"search":"jeans"}', 'ok' => 1]);
        Chat::addMessage($tid, (int)$conv['id'], 'out', 'ai', 'Yes! Slim fit jeans, Rs. 5,490.'); Db::q("UPDATE conversations SET last_message_at = NOW() - INTERVAL 3 HOUR, status=? WHERE id=?", [$status, $conv['id']]); return [$c, $conv];
    };
    [, $c1] = $mkChat('94700666001', 'opted_in'); [, $c2] = $mkChat('94700666002', 'opted_in'); $mkChat('94700666003', 'opted_in', 'human'); $mkChat('94700666004', 'unknown');
    $n = Automations::scan(); ok($n >= 3, "scheduled $n"); eq((int)Db::val("SELECT COUNT(*) FROM automation_runs WHERE tenant_id=? AND phone='94700666003'", [$tid]), 0, 'chats a person has taken over are left alone'); eq(Automations::scan(), 0, 'scanning again schedules nothing new');
    Chat::addMessage($tid, (int)$c2['id'], 'in', 'customer', 'actually I will take two'); // wrote again before the nudge went out
    $r = Automations::tick(); ok($r['sent'] >= 1, json_encode($r));
    $last = fn($conv) => Db::one("SELECT content FROM messages WHERE conversation_id=? AND sender='ai' ORDER BY id DESC LIMIT 1", [$conv['id']])['content'];
    ok(strpos($last($c1), 'jeans') !== false && strpos($last($c1), 'Ruwan') !== false, 'the nudge names the customer and the product: ' . $last($c1)); ok(strpos($last($c2), 'Slim fit') !== false, 'no nudge for a customer who wrote again');
    $why = fn($phone) => (string)Db::val("SELECT r.reason FROM automation_runs r WHERE r.tenant_id=? AND r.phone=? ORDER BY r.id DESC LIMIT 1", [$tid, $phone]); ok(stripos($why('94700666002'), 'wrote again') !== false, $why('94700666002')); ok(stripos($why('94700666004'), 'consent') !== false, 'unknown consent: ' . $why('94700666004'));
    Store::createLead($tid, ['phone' => '94700777001', 'name' => 'A', 'interest' => 'Bulk caps'], 'ai'); $l2 = Store::createLead($tid, ['phone' => '94700777002', 'name' => 'B', 'interest' => 'Uniforms'], 'ai')['lead'];
    Db::q("UPDATE crm_leads SET created_at = NOW() - INTERVAL 5 HOUR WHERE tenant_id=?", [$tid]); Automations::scan(); Store::setStage($tid, (int)$l2['id'], 'contacted'); Automations::tick();
    eq((int)Db::val("SELECT COUNT(*) FROM alerts WHERE tenant_id=? AND type='lead_stale'", [$tid]), 1, 'only the untouched lead alerts the team'); ok(strpos((string)Db::val("SELECT message FROM alerts WHERE tenant_id=? AND type='lead_stale'", [$tid]), 'Bulk caps') !== false);
    ok(stripos((string)Db::val("SELECT reason FROM automation_runs WHERE tenant_id=? AND subject_type='lead' AND subject_id=?", [$tid, $l2['id']]), 'already') !== false, 'a lead that was worked on is skipped');
});
t('pages: store, orders, leads, templates and follow-ups work end to end; staff work orders and leads but cannot change catalogue, templates or follow-ups', function () {
    $v = mkTenant('Pg'); $tid = $v['tid']; $c = new Http('http://127.0.0.1:8765'); eq($c->login($v['email'])['code'], 302); $csrf = $c->meta();
    $post = fn($path, $d) => $c->req('POST', $path, http_build_query($d + ['_csrf' => $csrf]));
    foreach (['store' => 'Your', 'orders' => 'Customer', 'leads' => 'Sales', 'templates' => 'WhatsApp', 'automations' => 'Customer'] as $p => $needle) { $r = $c->req('GET', "/app/$p"); eq($r['code'], 200, $p); ok(strpos($r['body'], $needle) !== false, "$p page renders"); }
    $post('/app/store', ['act' => 'sample']); ok((int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=?", [$tid]) >= 12, 'sample catalogue added');
    $post('/app/store', ['act' => 'product_save', 'sku' => 'xx-9', 'name' => 'Test <b>Item</b>', 'price' => '999', 'stock' => '4', 'variants' => 'S,M']); eq(Db::val("SELECT name FROM store_products WHERE tenant_id=? AND sku='XX-9'", [$tid]), 'Test <b>Item</b>'); ok(strpos($c->req('GET', '/app/store')['body'], '<b>Item</b>') === false, 'product names are escaped on the page');
    $post('/app/store', ['act' => 'connect']); ok(Store::connected($tid), 'one click connects the AI'); eq((int)Db::val("SELECT COUNT(*) FROM tools WHERE tenant_id=? AND name IN ('create_order','cancel_order','update_order','create_lead','confirm_lead','search_products','delivery_info')", [$tid]), 7);
    $r = $post('/app/orders', ['act' => 'order_create', 'phone' => '94771230000', 'customer_name' => 'Page Test', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => '1 Rd', 'payment_method' => 'Cash on delivery']); ok(stripos($r['head'], 'orders?o=ORD-1001') !== false, 'redirects to the new order');
    ok(strpos($c->req('GET', '/app/orders?o=ORD-1001')['body'], 'Move this order') !== false); $post('/app/orders', ['act' => 'order_status', 'order_no' => 'ORD-1001', 'status' => 'packed']); eq(Db::val("SELECT status FROM store_orders WHERE tenant_id=? AND order_no='ORD-1001'", [$tid]), 'packed');
    $post('/app/leads', ['act' => 'lead_create', 'name' => 'Pg Lead', 'phone' => '94771230001', 'interest' => 'Uniforms', 'value' => '25000']); $lid = (int)Db::val("SELECT id FROM crm_leads WHERE tenant_id=?", [$tid]); ok($lid > 0); $post('/app/leads', ['act' => 'lead_stage', 'id' => $lid, 'stage' => 'qualified']); eq(Db::val("SELECT stage FROM crm_leads WHERE id=?", [$lid]), 'qualified'); ok(strpos($c->req('GET', "/app/leads?l=$lid")['body'], 'Uniforms') !== false);
    $post('/app/templates', ['act' => 'tpl_save', 'name' => 'My Template', 'language' => 'en', 'category' => 'utility', 'body' => 'Hi {name}, your order {order_no} is ready for collection today.']); $tpl1 = (int)Db::val("SELECT id FROM wa_templates WHERE tenant_id=? AND name='my_template'", [$tid]); ok($tpl1 > 0, 'name is normalised'); $page = $c->req('GET', "/app/templates?t=$tpl1")['body']; ok(strpos($page, 'data-tpl-preview') !== false && strpos($page, 'order_no') !== false);
    $post('/app/automations', ['act' => 'auto_recommended']); ok((int)Db::val("SELECT COUNT(*) FROM automations WHERE tenant_id=?", [$tid]) >= 7); $post('/app/automations', ['act' => 'quiet_save', 'quiet_start' => '22:00', 'quiet_end' => '07:00']); eq(Tenant::get($tid)['cfg']['quiet_start'], '22:00');
    $post('/app/automations', ['act' => 'auto_save', 'name' => 'Custom', 'trigger_type' => 'order_delivered', 'delay_minutes' => '30', 'body' => 'Thanks {name}!', 'source' => 'manual', 'min_total' => '2000', 'is_active' => '1']); $a = Db::one("SELECT * FROM automations WHERE tenant_id=? AND name='Custom'", [$tid]); eq((int)$a['delay_minutes'], 30); $cond = json_decode($a['conditions'], true); eq([$cond['source'], (float)$cond['min_total']], ['manual', 2000.0]);
    $s = mkTenant('PgStaff', 'agent'); Db::update('users', ['tenant_id' => $tid], 'id=?', [$s['uid']]); $sc = new Http('http://127.0.0.1:8765'); $sc->login($s['email']); $scsrf = $sc->meta(); $spost = fn($path, $d) => $sc->req('POST', $path, http_build_query($d + ['_csrf' => $scsrf]));
    eq($sc->req('GET', '/app/orders')['code'], 200); $spost('/app/orders', ['act' => 'order_status', 'order_no' => 'ORD-1001', 'status' => 'shipped', 'courier' => 'Domex', 'tracking' => 'D1']); eq(Db::val("SELECT status FROM store_orders WHERE tenant_id=? AND order_no='ORD-1001'", [$tid]), 'shipped', 'staff can ship orders');
    $spost('/app/leads', ['act' => 'lead_stage', 'id' => $lid, 'stage' => 'won']); eq(Db::val("SELECT stage FROM crm_leads WHERE id=?", [$lid]), 'won', 'staff can work leads');
    $spost('/app/store', ['act' => 'product_save', 'sku' => 'ST-1', 'name' => 'Staff product', 'price' => '1']); eq((int)Db::val("SELECT COUNT(*) FROM store_products WHERE tenant_id=? AND sku='ST-1'", [$tid]), 0, 'staff cannot change the catalogue');
    $spost('/app/templates', ['act' => 'tpl_save', 'name' => 'staff_tpl', 'language' => 'en', 'category' => 'utility', 'body' => 'Hello there {name}, thanks a lot for your order.']); eq((int)Db::val("SELECT COUNT(*) FROM wa_templates WHERE tenant_id=? AND name='staff_tpl'", [$tid]), 0, 'staff cannot create templates');
    $spost('/app/automations', ['act' => 'auto_delete', 'id' => $a['id']]); ok(Db::val("SELECT id FROM automations WHERE id=?", [$a['id']]) !== null, 'staff cannot delete follow-ups');
    $o2 = mkTenant('PgOther'); Store::seedSample($o2['tid']); $mk = fn($ph) => Store::createOrder($o2['tid'], ['phone' => $ph, 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => 'x'], 'manual'); $mk('94771239998'); $oo = $mk('94771239999'); eq($oo['order_no'], 'ORD-1002');
    ok(strpos($c->req('GET', '/app/orders?o=ORD-1002')['body'], '94771239999') === false, "another business's order is not shown by number"); $post('/app/orders', ['act' => 'order_status', 'order_no' => 'ORD-1002', 'status' => 'packed']); eq(Db::val("SELECT status FROM store_orders WHERE id=?", [$oo['id']]), 'processing', 'and cannot be changed');
});
t('actions: when the model changes the details after the customer said yes, it is told exactly what the customer saw, and cannot sneak in changes', function () {
    $v = mkTenant('Exact'); $tid = $v['tid']; Store::seedSample($tid); Store::connect($tid);
    $c = Chat::contact($tid, 0, 'whatsapp', 'ex1', 'Ex', '94700888111'); $conv = Chat::conversation($tid, null, 'whatsapp', $c, null); $cid = (int)$conv['id'];
    $run = fn($a) => ToolRunner::run(Db::one("SELECT * FROM tools WHERE tenant_id=? AND name='create_order'", [$tid]), $a, $c, $cid); $say = fn($m) => Chat::addMessage($tid, $cid, 'in', 'customer', $m);
    $shown = ['items' => 'AC-503:-:2:Black', 'delivery_city' => 'Kandy', 'delivery_address' => '45 Temple Road', 'payment_method' => 'Cash on delivery'];
    $say('2 caps to Kandy please'); ok($run($shown)['ok']); $say('yes');
    $r = $run($shown + ['customer_name' => 'Nimal', 'customer_confirmed' => true]); ok(!$r['ok'], 'an extra field after the preview is refused'); ok(strpos((string)$r['error'], 'EXACTLY') !== false && strpos((string)$r['error'], '"items":"AC-503:-:2:Black"') !== false && strpos((string)$r['error'], 'Nimal') === false, 'the error carries the details the customer saw: ' . $r['error']);
    $r = $run(array_merge($shown, ['delivery_city' => 'Galle', 'customer_confirmed' => true])); ok(!$r['ok'], 'changed details are still refused'); eq((int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=?", [$tid]), 0);
    $r = $run($shown + ['customer_confirmed' => true]); ok($r['ok'], 'retrying with exactly what the customer saw works: ' . $r['error']); eq((int)Db::val("SELECT COUNT(*) FROM store_orders WHERE tenant_id=?", [$tid]), 1);
});
