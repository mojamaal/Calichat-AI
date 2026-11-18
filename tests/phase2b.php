<?php
declare(strict_types=1);
/** Phase 2 feature tests: interactive messages, invoices, bank slips. Included by tests/run.php. */
use CaliChat\Core\{Crypto, Db, Tenant};
use CaliChat\Services\{Chat, Interactive, Meta, Outbound, Slips, Store, ToolRunner};

echo "\nPhase 2: interactive messages, invoices, bank slips\n";
$logLines = fn(string $file) => is_file(sys_get_temp_dir() . '/' . $file) ? array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/' . $file, FILE_IGNORE_NEW_LINES)) : [];
$stubOut = fn() => $logLines('cc_graph_stub.log');
$mkStore = $mkStore ?? function (string $name) { $v = mkTenant($name); Store::seedSample($v['tid']); Store::connect($v['tid']); return $v; };

t('interactive: buttons and product lists follow what the assistant just did, in the customer\'s script, on Meta WhatsApp only', function () use ($waChannel, $stubOut) {
    $v = mkTenant('Int'); $tid = $v['tid']; $ch = Db::one("SELECT * FROM channels WHERE id=?", [$waChannel($tid, '8001')]); $tenant = Tenant::get($tid); $tw = array_merge($ch, ['type' => 'twilio_whatsapp']);
    $f = fn($r, $text = 'Please confirm: 2 caps, total Rs. 3,450', $chan = null) => Interactive::forReply($tenant, $chan ?? $ch, $r, $text);
    $b = $f(['awaiting_confirmation' => true]); eq(count($b['buttons']), 2); eq($b['buttons'][0]['title'], 'Yes, confirm'); ok(mb_strlen($b['buttons'][1]['title']) <= 20);
    ok(strpos(json_encode($f(['awaiting_confirmation' => true], 'ඇණවුම තහවුරු කරන්න'), JSON_UNESCAPED_UNICODE), 'ඔව්') !== false, 'Sinhala buttons'); ok(strpos(json_encode($f(['awaiting_confirmation' => true], 'உறுதிப்படுத்தவும்'), JSON_UNESCAPED_UNICODE), 'ஆம்') !== false, 'Tamil buttons');
    $prods = array_map(fn($i) => ['sku' => "SKU-$i", 'name' => "A rather long product name number $i", 'price' => 1000 * $i], range(1, 3)); $l = $f(['products' => $prods]); eq(count($l['list']['rows']), 3); eq($l['list']['rows'][0]['description'], 'Rs. 1,000');
    eq($f(['products' => [$prods[0]]]), [], 'one product needs no list'); eq($f(['products' => array_fill(0, 11, $prods[0])]), [], 'more than 10 is too many for a list'); eq($f(['awaiting_confirmation' => true], str_repeat('x', 1200)), [], 'long text stays plain');
    eq($f(['awaiting_confirmation' => true], 'confirm?', $tw), [], 'Twilio gets plain text'); Tenant::saveSettings($tid, ['interactive' => 0]); eq(Interactive::forReply(Tenant::get($tid), $ch, ['awaiting_confirmation' => true], 'confirm?'), [], 'owners can switch it off');
    @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
    ok(Interactive::deliver($ch, '94700111222', 'Confirm?', ['buttons' => $b['buttons']])['ok']); ok(Interactive::deliver($ch, '94700111222', 'Pick one', ['list' => $l['list']])['ok']); ok(Meta::sendImage($ch, '94700111222', 'https://cdn.example.lk/a.jpg', 'Cap')['ok']);
    $sent = array_values(array_filter($stubOut(), fn($x) => $x['p'] === '/8001/messages')); eq(count($sent), 3);
    eq($sent[0]['j']['interactive']['type'], 'button'); eq($sent[0]['j']['interactive']['action']['buttons'][0]['reply']['id'], 'confirm_yes'); eq($sent[1]['j']['interactive']['type'], 'list'); ok(mb_strlen($sent[1]['j']['interactive']['action']['sections'][0]['rows'][0]['title']) <= 24, 'row titles fit Meta limits'); eq($sent[2]['j']['type'], 'image'); eq($sent[2]['j']['image']['link'], 'https://cdn.example.lk/a.jpg');
    $ev = Meta::parse(['type' => 'whatsapp', 'external_id' => '8001'], ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '8001'], 'messages' => [['from' => '9470011', 'id' => 'b1', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'confirm_yes', 'title' => 'Yes, confirm']]], ['from' => '9470011', 'id' => 'l1', 'type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'TS-001', 'title' => 'Cotton T-Shirt']]]]]]]]]]);
    eq([$ev[0]['text'], $ev[1]['text']], ['Yes, confirm', 'Cotton T-Shirt'], 'a tap arrives as a normal message');
});
t('invoices: a signed public link per order, tamper-proof, tenant-bound; the AI can only fetch its own customer\'s', function () use ($mkStore, $h, $waChannel) {
    $v = $mkStore('Inv'); $tid = $v['tid']; Tenant::saveSettings($tid, ['store_invoice_info' => "12 Galle Rd, Colombo\nBank: 123-456"]);
    $o = Store::createOrder($tid, ['phone' => '94770005001', 'customer_name' => 'Ruwan', 'items' => 'AC-503:-:2:Black', 'delivery_city' => 'Kandy', 'delivery_address' => '5 Hill St', 'payment_method' => 'Bank transfer'], 'ai');
    $url = Store::invoiceUrl($tid, $o['order_no']); $path = preg_replace('#^https?://[^/]+#', '', $url); $r = $h->req('GET', $path); eq($r['code'], 200);
    ok(strpos($r['body'], 'ORD-1001') !== false && strpos($r['body'], 'Rs. 3,450.00') !== false && strpos($r['body'], 'Ruwan') !== false && strpos($r['body'], 'Bank: 123-456') !== false, 'invoice shows the order'); ok(stripos($r['head'], 'noindex') !== false);
    eq($h->req('GET', preg_replace('/.$/', 'X', $path))['code'], 404, 'a changed signature fails'); eq($h->req('GET', str_replace('/ORD-1001/', '/ORD-1002/', $path))['code'], 404, 'another order number fails'); eq($h->req('GET', str_replace("/invoice/$tid/", '/invoice/' . ($tid + 1) . '/', $path))['code'], 404, 'another tenant fails');
    $c = Chat::contact($tid, 0, 'whatsapp', 'inv1', 'Ruwan', '94770005001'); $conv = Chat::conversation($tid, null, 'whatsapp', $c, null); $tool = Db::one("SELECT * FROM tools WHERE tenant_id=? AND name='get_invoice_link'", [$tid]);
    $r = ToolRunner::run($tool, ['order_no' => 'ORD-1001'], $c, (int)$conv['id']); ok($r['ok'] && strpos(json_encode($r['data'], JSON_UNESCAPED_SLASHES), '/invoice/' . $tid . '/ORD-1001/') !== false, 'own order link');
    $other = Chat::contact($tid, 0, 'whatsapp', 'inv2', 'Other', '94770005999'); $r = ToolRunner::run($tool, ['order_no' => 'ORD-1001'], $other, (int)Chat::conversation($tid, null, 'whatsapp', $other, null)['id']); ok(!$r['ok'], "someone else's order has no link");
    $ch = $waChannel($tid, '8002'); $cc = Chat::contact($tid, $ch, 'whatsapp', '94770005001', 'Ruwan', '94770005001'); $cv = Chat::conversation($tid, Db::one("SELECT * FROM channels WHERE id=?", [$ch]), 'whatsapp', $cc, null); Chat::addMessage($tid, (int)$cv['id'], 'in', 'customer', 'invoice please');
    $x = Outbound::toPhone($tid, '94770005001', 'Invoice: ' . $url); ok($x['ok'], (string)$x['error']); Db::q("UPDATE conversations SET last_customer_at = NOW() - INTERVAL 2 DAY WHERE id=?", [$cv['id']]);
    $x = Outbound::toPhone($tid, '94770005001', 'again'); ok(!$x['ok'] && strpos((string)$x['error'], '24 hours') !== false, 'outside the 24-hour window it explains why'); ok(!Outbound::toPhone($tid, '94770999999', 'hi')['ok'], 'a number that never chatted');
});
t('bank slips: a payment image becomes a verifiable record, the order waits for a person, duplicates and non-slips are handled, files stay tenant-private', function () use ($mkStore) {
    $v = $mkStore('Slip'); $tid = $v['tid']; $phone = '94770006001'; $c = Chat::contact($tid, 0, 'whatsapp', 'sl1', 'Sam', $phone); $conv = Chat::conversation($tid, null, 'whatsapp', $c, null); $tenant = Tenant::get($tid);
    $mk = fn($pay, $items = 'AC-503:-:2:Black') => Store::createOrder($tid, ['phone' => $phone, 'customer_name' => 'Sam', 'items' => $items, 'delivery_city' => 'Kandy', 'delivery_address' => 'x', 'payment_method' => $pay], 'ai');
    $img = "\xFF\xD8\xFF\xE0fake-jpeg-bytes-1"; $calls = 0;
    Slips::$extractor = function ($bytes, $mime) use (&$calls) { $calls++; return strpos($bytes, 'photo') !== false ? ['is_payment_slip' => false] : ['is_payment_slip' => true, 'amount' => 3450, 'reference' => 'TXN778899', 'bank' => 'Commercial Bank', 'date' => '2026-11-11', 'confidence' => 'high']; };
    try {
        eq(Slips::maybe($tenant, $c, (int)$conv['id'], $img, 'image/jpeg'), null, 'no unpaid bank-transfer order: not treated as a slip'); eq($calls, 0, 'and no AI call is spent on it');
        $mk('Cash on delivery'); eq(Slips::maybe($tenant, $c, (int)$conv['id'], $img, 'image/jpeg'), null, 'cash on delivery orders do not wait for slips');
        $o = $mk('Bank transfer'); eq((float)$o['total'], 3450.0);
        eq(Slips::maybe($tenant, $c, (int)$conv['id'], "\xFF\xD8\xFFproduct-photo-bytes", 'image/jpeg'), null, 'a product photo is not a slip'); eq(Db::val("SELECT payment_status FROM store_orders WHERE id=?", [$o['id']]), 'unpaid');
        $t = Slips::maybe($tenant, $c, (int)$conv['id'], $img, 'image/jpeg'); ok($t !== null && strpos($t, 'ORD-1002') !== false && strpos($t, 'matches the order total') !== false && strpos($t, 'Do NOT say the payment is confirmed') !== false, (string)$t);
        eq(Db::val("SELECT payment_status FROM store_orders WHERE id=?", [$o['id']]), 'review', 'the order waits for a person'); $s = Db::one("SELECT * FROM payment_slips WHERE order_id=?", [$o['id']]); eq((float)$s['amount'], 3450.0); eq((int)$s['amount_matches'], 1); eq($s['reference'], 'TXN778899'); ok(is_file(Slips::dir($tid) . '/' . $s['file']), 'file kept for staff');
        eq((int)Db::val("SELECT COUNT(*) FROM alerts WHERE tenant_id=? AND type='payment_slip'", [$tid]), 1, 'team alerted'); Slips::maybe($tenant, $c, (int)$conv['id'], $img, 'image/jpeg'); eq((int)Db::val("SELECT COUNT(*) FROM payment_slips WHERE order_id=?", [$o['id']]), 1, 'the same image again is not a second slip');
        $o2 = $mk('Card', 'TS-001:M:1:Black'); Slips::$extractor = fn() => ['is_payment_slip' => true, 'amount' => 1000, 'confidence' => 'medium']; $t2 = Slips::maybe($tenant, $c, (int)$conv['id'], $img . '2', 'image/jpeg'); ok(strpos((string)$t2, 'does NOT match') !== false, 'a wrong amount is called out: ' . $t2); eq((int)Db::val("SELECT amount_matches FROM payment_slips WHERE order_id=?", [$o2['id']]), 0);
        $r = Slips::decide($tid, (int)$s['id'], true, $v['uid']); ok($r['ok']); eq(Db::val("SELECT payment_status FROM store_orders WHERE id=?", [$o['id']]), 'paid'); eq(Db::val("SELECT status FROM payment_slips WHERE id=?", [$s['id']]), 'verified'); ok(!Slips::decide($tid, (int)$s['id'], true, $v['uid'])['ok'], 'a slip is decided once');
        $s2 = Db::one("SELECT id FROM payment_slips WHERE order_id=?", [$o2['id']]); Slips::decide($tid, (int)$s2['id'], false, $v['uid']); eq(Db::val("SELECT payment_status FROM store_orders WHERE id=?", [$o2['id']]), 'unpaid', 'a rejected slip puts the order back to unpaid');
        $own = new Http('http://127.0.0.1:8765'); $own->login($v['email']); $r = $own->req('GET', '/api/slips/file?id=' . $s['id']); eq($r['code'], 200); ok(stripos($r['head'], 'image/jpeg') !== false && strpos($r['body'], 'fake-jpeg-bytes-1') !== false);
        $b = mkTenant('SlipOther'); $oc = new Http('http://127.0.0.1:8765'); $oc->login($b['email']); eq($oc->req('GET', '/api/slips/file?id=' . $s['id'])['code'], 404, 'another business cannot read it'); eq((new Http('http://127.0.0.1:8765'))->req('GET', '/api/slips/file?id=' . $s['id'])['code'], 401, 'signed-out visitors cannot');
    } finally { Slips::$extractor = null; }
});
