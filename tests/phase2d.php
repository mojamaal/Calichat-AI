<?php
declare(strict_types=1);
/** Phase 2 tests: voice-note replies, messaging settings, pages for the new modules. Included by tests/run.php. */
use CaliChat\Core\{Crypto, Db, Tenant};
use CaliChat\Services\{Appointments, Chat, Store, Voice};

echo "\nPhase 2: voice replies and the new pages\n";

t('voice: only short, link-free answers are spoken; the voice note is uploaded and sent; failures fall back to text; owners control it', function () use ($waChannel) {
    eq(Voice::speakable("*Your order* ORD-1001 is placed! 😊\n- Total: Rs. 3,450"), 'Your order ORD-1001 is placed! Total: Rs. 3,450', 'formatting marks and emoji are removed'); eq(Voice::speakable('See https://x.lk/invoice for details'), null, 'links are not read out'); eq(Voice::speakable(str_repeat('word ', 200)), null, 'long answers stay text'); eq(Voice::speakable('   '), null);
    $v = mkTenant('Voice'); $tid = $v['tid']; $ch = Db::one("SELECT * FROM channels WHERE id=?", [$waChannel($tid, '8501')]); $tenant = Tenant::get($tid); $tw = array_merge($ch, ['type' => 'twilio_whatsapp']);
    Voice::$synth = fn($text) => "OggS\x00fake-opus-for:" . $text;
    try {
        ok(!Voice::enabled($tenant, $ch), 'off until the owner switches it on'); Tenant::saveSettings($tid, ['voice_replies' => 1]); $tenant = Tenant::get($tid); ok(Voice::enabled($tenant, $ch)); ok(!Voice::enabled($tenant, $tw), 'Meta WhatsApp only');
        @unlink(sys_get_temp_dir() . '/cc_media_stub.log'); @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
        $r = Voice::send($tenant, $ch, '94770010001', 'Your order is on its way.'); ok($r['ok'], (string)$r['error']);
        $media = array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_media_stub.log', FILE_IGNORE_NEW_LINES)); eq(count($media), 1); eq($media[0]['type'], 'audio/ogg'); eq($media[0]['mp'], 'whatsapp'); eq($media[0]['head'], bin2hex('OggS'), 'an Ogg file reached WhatsApp'); ok($media[0]['size'] > 20);
        $sent = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_graph_stub.log', FILE_IGNORE_NEW_LINES)), fn($x) => $x['p'] === '/8501/messages')); eq($sent[0]['j']['type'], 'audio'); ok(strpos($sent[0]['j']['audio']['id'], 'MEDIA') === 0, 'sent by the uploaded media id'); eq($sent[0]['j']['to'], '94770010001');
        ok(!Voice::send($tenant, $ch, '94770010001', 'Visit https://x.lk now')['ok'], 'a reply with a link is not sent as audio'); Voice::$synth = fn($t) => null; ok(!Voice::send($tenant, $ch, '94770010001', 'Hello there')['ok'], 'speech failure is reported so the caller can send text');
        Voice::$synth = function ($t) { throw new RuntimeException('TTS down'); }; ok(!Voice::send($tenant, $ch, '94770010001', 'Hello there')['ok'], 'an exception never escapes');
    } finally { Voice::$synth = null; }
    $c = new Http('http://127.0.0.1:8765'); $c->login($v['email']); $csrf = $c->meta(); $c->req('POST', '/app/settings', http_build_query(['_csrf' => $csrf, 'act' => 'messaging_save', 'voice_name' => 'Puck'])); $cfg = Tenant::get($tid)['cfg']; eq([(int)$cfg['voice_replies'], (int)$cfg['interactive'], $cfg['voice_name']], [0, 0, 'Puck'], 'unticked boxes switch the options off');
    $c->req('POST', '/app/settings', http_build_query(['_csrf' => $csrf, 'act' => 'messaging_save', 'voice_replies' => '1', 'interactive' => '1', 'voice_name' => 'NotAVoice'])); eq(Tenant::get($tid)['cfg']['voice_name'], 'Kore', 'unknown voices fall back'); ok(strpos($c->req('GET', '/app/settings')['body'], 'Answer voice notes') !== false);
});
t('pages: appointments, store extras and the payment queue render and work for owners; staff keep to bookings', function () {
    $v = mkTenant('Pg2'); $tid = $v['tid']; $c = new Http('http://127.0.0.1:8765'); $c->login($v['email']); $csrf = $c->meta(); $post = fn($p, $d) => $c->req('POST', $p, http_build_query($d + ['_csrf' => $csrf]));
    $r = $c->req('GET', '/app/appointments'); eq($r['code'], 200); ok(strpos($r['body'], 'Customer') !== false && strpos($r['body'], 'Add a service') !== false);
    $post('/app/appointments', ['act' => 'svc_save', 'name' => 'Consultation <b>', 'duration_min' => '45', 'price' => '2500', 'capacity' => '1']); $svc = Db::one("SELECT * FROM appt_services WHERE tenant_id=?", [$tid]); ok($svc !== null); eq((int)$svc['duration_min'], 45); ok(strpos($c->req('GET', '/app/appointments')['body'], 'Consultation <b>') === false, 'service names are escaped');
    $d = (new DateTime('today +4 days', new DateTimeZone('Asia/Colombo'))); while ((int)$d->format('N') === 7) $d->modify('+1 day'); $date = $d->format('Y-m-d');
    $page = $c->req('GET', '/app/appointments?date=' . $date . '&bs=' . $svc['id'] . '&bd=' . $date)['body']; ok(strpos($page, '<option>09:00</option>') !== false, 'free times offered');
    $post('/app/appointments', ['act' => 'appt_book', 'service_id' => $svc['id'], 'date' => $date, 'time' => '09:00', 'name' => 'Page Test', 'phone' => '94770011001', 'notes' => 'first visit']); $a = Db::one("SELECT * FROM appointments WHERE tenant_id=?", [$tid]); ok($a !== null && $a['source'] === 'manual' && $a['notes'] === 'first visit', 'booked from the page');
    $post('/app/appointments', ['act' => 'appt_book', 'service_id' => $svc['id'], 'date' => $date, 'time' => '09:00', 'name' => 'Clash', 'phone' => '94770011002']); eq((int)Db::val("SELECT COUNT(*) FROM appointments WHERE tenant_id=?", [$tid]), 1, 'the page refuses a double booking'); ok(strpos($c->req('GET', '/app/appointments?date=' . $date)['body'], 'Page Test') !== false);
    $s = mkTenant('Pg2Staff', 'agent'); Db::update('users', ['tenant_id' => $tid], 'id=?', [$s['uid']]); $sc = new Http('http://127.0.0.1:8765'); $sc->login($s['email']); $scs = $sc->meta(); $sp = fn($d) => $sc->req('POST', '/app/appointments', http_build_query($d + ['_csrf' => $scs]));
    $sp(['act' => 'svc_save', 'name' => 'Staff service']); eq((int)Db::val("SELECT COUNT(*) FROM appt_services WHERE tenant_id=?", [$tid]), 1, 'staff cannot create services'); $sp(['act' => 'svc_delete', 'id' => $svc['id']]); ok(Db::val("SELECT id FROM appt_services WHERE id=?", [$svc['id']]) !== null, 'or delete them');
    $sp(['act' => 'appt_status', 'id' => $a['id'], 'status' => 'completed', 'date' => $date]); eq(Db::val("SELECT status FROM appointments WHERE id=?", [$a['id']]), 'completed', 'staff can mark visits done');
    $o2 = mkTenant('Pg2Other'); $oc = new Http('http://127.0.0.1:8765'); $oc->login($o2['email']); $oc->req('POST', '/app/appointments', http_build_query(['_csrf' => $oc->meta(), 'act' => 'appt_status', 'id' => $a['id'], 'status' => 'no_show'])); eq(Db::val("SELECT status FROM appointments WHERE id=?", [$a['id']]), 'completed', "another business cannot change it");
    // orders page: payment queue and invoice buttons
    Store::seedSample($tid); $o = Store::createOrder($tid, ['phone' => '94770011003', 'customer_name' => 'Q', 'items' => 'AC-503:-:1:Black', 'delivery_city' => 'Colombo', 'delivery_address' => 'x', 'payment_method' => 'Bank transfer'], 'manual'); Db::update('store_orders', ['payment_status' => 'review'], 'id=?', [$o['id']]);
    $body = $c->req('GET', '/app/orders?pay=review')['body']; ok(strpos($body, $o['order_no']) !== false && strpos($body, 'Payments to verify') !== false, 'the payment queue lists orders waiting for verification'); $detail = $c->req('GET', '/app/orders?o=' . $o['order_no'])['body']; ok(strpos($detail, 'Send link to customer') !== false && strpos($detail, '/invoice/' . $tid . '/') !== false, 'invoice buttons');
    $store = $c->req('GET', '/app/store')['body']; ok(strpos($store, 'Import from WooCommerce or Shopify') !== false && strpos($store, 'Shown on invoices') !== false);
    $fu = $c->req('GET', '/app/automations')['body']; ok(strpos($fu, 'SMS fallback') !== false && strpos($fu, 'Appointment') !== false, 'follow-ups page shows SMS and appointment triggers');
});
