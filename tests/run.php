<?php
declare(strict_types=1);
/**
 * Test runner (no dependencies):  php tests/run.php [filter]
 * Builds a throwaway database (calichat_test) from the migrations, runs unit tests in-process and
 * integration/security tests against a real PHP server instance. Never touches the dev database
 * and never calls Gemini or Meta.
 */
if (PHP_SAPI !== 'cli') exit('CLI only');
$TEST_DB = 'calichat_test'; $PORT = 8765;
foreach (['DB_NAME' => $TEST_DB, 'QUEUE_INLINE_DRAIN' => '0', 'APP_URL' => "http://127.0.0.1:$PORT", 'APP_ENV' => 'local', 'APP_DEBUG' => '0', 'GEMINI_API_KEY' => '', 'GEMINI_DISABLED' => '1', 'TENCENT_MEMORY_URL' => '', 'ALLOW_PRIVATE_HOSTS' => '1', 'META_GRAPH_BASE' => 'http://127.0.0.1:8766', 'META_OAUTH_BASE' => 'http://127.0.0.1:8766', 'STORAGE_PATH' => sys_get_temp_dir() . '/cc_test_storage'] as $k => $v) putenv("$k=$v");
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\{Auth, Crypto, Db, Guard, Migrator, Net, Platform, Tenant};
use CaliChat\Memory\MySqlMemory;
use CaliChat\Services\{Actions, Chat, DemoData, Knowledge, Meta, MetaConnect, Onboarding, Queue, ToolRunner, Twilio};

$pass = 0; $fail = 0; $filter = $argv[1] ?? ''; $current = '';
function t(string $name, callable $fn): void {
    global $pass, $fail, $filter, $current;
    if ($filter !== '' && stripos($name, $filter) === false) return;
    $current = $name;
    try { $fn(); echo "  ✓ $name\n"; $pass++; }
    catch (\Throwable $e) { echo "  ✗ $name\n      " . $e->getMessage() . "\n"; $fail++; }
}
function ok($c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
function eq($a, $b, string $m = ''): void { if ($a !== $b) throw new RuntimeException(($m ? "$m: " : '') . 'expected ' . json_encode($b) . ' got ' . json_encode($a)); }
function throws(callable $f, string $m = 'expected an exception'): void { try { $f(); } catch (\Throwable $e) { return; } throw new RuntimeException($m); }

// ---- fresh database from migrations (also proves the migrations run from zero) ----
$root = new PDO('mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';charset=utf8mb4', (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP DATABASE IF EXISTS `$TEST_DB`"); $root->exec("CREATE DATABASE `$TEST_DB` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $root->exec("USE `$TEST_DB`");
$n = Migrator::run($root); echo "Test database ready ($n migrations)\n\n";

function mkTenant(string $name, string $role = 'owner', string $email = null): array {
    $tid = Db::insert('tenants', ['name' => $name, 'slug' => slugify($name) . rand_key(2), 'settings' => '{}']);
    $email = $email ?: strtolower(slugify($name)) . rand_key(2) . '@test.local';
    $uid = Db::insert('users', ['tenant_id' => $tid, 'name' => "$name Owner", 'email' => $email, 'password_hash' => password_hash('Correct-Horse-9', PASSWORD_DEFAULT), 'role' => $role]);
    Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Bot', 'instructions' => 'x']);
    return ['tid' => $tid, 'uid' => $uid, 'email' => $email];
}

echo "Unit tests\n";
t('crypto: v2 roundtrip + tamper detection', function () {
    $c = Crypto::encrypt('s3cret'); ok(strpos($c, 'v2:') === 0); eq(Crypto::decrypt($c), 's3cret');
    $bad = substr($c, 0, -3) . 'AAA'; eq(Crypto::decrypt($bad), null, 'tampered ciphertext must not decrypt');
    eq(Crypto::decrypt(null), null); eq(Crypto::encrypt(''), null);
});
t('crypto: legacy v1 blobs are still readable and flagged for rotation', function () {
    $k = hash('sha256', (string)env('APP_KEY'), true); $iv = random_bytes(12);
    $ct = openssl_encrypt('old', 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag); $blob = 'v1:' . base64_encode($iv . $tag . $ct);
    eq(Crypto::decrypt($blob), 'old'); ok(Crypto::needsRotation($blob)); ok(!Crypto::needsRotation(Crypto::encrypt('new')));
});
t('auth: password policy', function () {
    ok(Auth::passwordProblem('short1!') !== null); ok(Auth::passwordProblem('password1') !== null);
    ok(Auth::passwordProblem('alllowercase') !== null, 'single character class and under 16 chars');
    ok(Auth::passwordProblem('a-long-lowercase-passphrase') === null, 'long passphrases are fine');
    ok(Auth::passwordProblem('Correct-Horse-9', 'someone@x.com') === null);
    ok(Auth::passwordProblem('Sandaruwan-2026!', 'sandaruwan@x.com') !== null, 'contains email name');
});
$p = fn(array $o) => $o + ['name' => 'order_no', 'type' => 'string'];
t('tools: path parameter traversal is rejected', function () use ($p) {
    foreach (['..', '.', '../etc', 'a/b', 'a\\b', '%2e%2e', 'x y'] as $bad) throws(fn() => ToolRunner::validate($p([]), $bad, true), "path value '$bad' must be rejected");
    eq(ToolRunner::validate($p([]), 'ORD-1001', true), 'ORD-1001');
});
t('tools: pattern / enum / length / type validation', function () use ($p) {
    eq(ToolRunner::validate($p(['pattern' => '^ORD-[0-9]{3,8}$']), 'ORD-123'), 'ORD-123');
    throws(fn() => ToolRunner::validate($p(['pattern' => '^ORD-[0-9]{3,8}$']), 'DROP TABLE'));
    throws(fn() => ToolRunner::validate($p(['enum' => 'a,b']), 'c')); eq(ToolRunner::validate($p(['enum' => 'a,b']), 'b'), 'b');
    throws(fn() => ToolRunner::validate($p(['max_len' => 5]), 'toolong')); throws(fn() => ToolRunner::validate($p([]), "bad\x00byte"));
    throws(fn() => ToolRunner::validate($p(['type' => 'integer']), 'abc')); eq(ToolRunner::validate($p(['type' => 'integer', 'min' => 1, 'max' => 5]), '3'), 3);
    throws(fn() => ToolRunner::validate($p(['type' => 'integer', 'max' => 5]), '9')); throws(fn() => ToolRunner::validate($p([]), ['x']));
});
t('tools: sanitize drops secret keys and masks card numbers', function () {
    $r = ToolRunner::sanitize(['name' => 'A', 'api_key' => 'k', 'Password' => 'p', 'nested' => ['token' => 't', 'note' => 'card 4111 1111 1111 1111 ok', 'n' => 'order 1234567890123 plain'], 'x_otp' => 1]);
    ok(!isset($r['api_key']) && !isset($r['Password']) && !isset($r['nested']['token']) && !isset($r['x_otp']), 'secret keys removed');
    ok(strpos($r['nested']['note'], '4111') === false || substr_count($r['nested']['note'], '1111') === 1, 'card masked, last 4 kept');
    ok(strpos($r['nested']['n'], '1234567890123') !== false, 'non-Luhn digit runs are left alone');
});
t('tools: response field allow-list is recursive', function () {
    $d = ToolRunner::pick(['data' => [['sku' => 1, 'cost' => 9, 'name' => 'a'], ['sku' => 2, 'cost' => 8, 'name' => 'b']]], ['sku', 'name']);
    eq($d, ['data' => [['sku' => 1, 'name' => 'a'], ['sku' => 2, 'name' => 'b']]]);
});
t('tools: oversized lists are cut at item boundaries', function () {
    $items = array_map(fn($i) => ['i' => $i, 'text' => str_repeat('x', 900)], range(1, 30));
    $r = ToolRunner::forModel(['ok' => true, 'data' => $items, 'user_error' => false]);
    ok($r['truncated'] === true && count($r['data']) < 30 && count($r['data']) >= 1, 'truncated list'); ok(json_decode(json_encode($r), true) !== null);
});
t('meta: signature verification', function () {
    $raw = '{"a":1}'; $sig = 'sha256=' . hash_hmac('sha256', $raw, 'sec');
    ok(Meta::verifySignature($raw, $sig, 'sec')); ok(!Meta::verifySignature($raw, $sig, 'other')); ok(!Meta::verifySignature($raw, null, 'sec')); ok(!Meta::verifySignature($raw, $sig, null), 'missing secret rejects');
});
t('meta: parses WhatsApp text, voice, image and Messenger', function () {
    $wa = ['type' => 'whatsapp', 'external_id' => '555'];
    $msgs = [
        ['from' => '94771', 'id' => 'm1', 'type' => 'text', 'text' => ['body' => 'hi']],
        ['from' => '94771', 'id' => 'm2', 'type' => 'audio', 'audio' => ['id' => 'A1', 'mime_type' => 'audio/ogg']],
        ['from' => '94771', 'id' => 'm3', 'type' => 'image', 'image' => ['id' => 'I1', 'caption' => 'look']],
    ];
    $value = ['metadata' => ['phone_number_id' => '555'], 'contacts' => [['wa_id' => '94771', 'profile' => ['name' => 'Nimal']]], 'messages' => $msgs];
    $ev = Meta::parse($wa, ['entry' => [['changes' => [['value' => $value]]]]]);
    eq(count($ev), 3); eq($ev[0]['text'], 'hi'); eq($ev[0]['name'], 'Nimal'); eq($ev[1]['type'], 'audio'); eq($ev[1]['media_ref'], 'A1'); eq($ev[2]['type'], 'image'); eq($ev[2]['text'], 'look');
    $otherNumber = ['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => 'OTHER'],
        'messages' => [['from' => '1', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'a']]],
    ]]]]]];
    eq(Meta::parse($wa, $otherNumber), [], 'messages for a different phone number id are ignored');
    $ms = Meta::parse(['type' => 'messenger', 'external_id' => 'P'], ['entry' => [['messaging' => [['sender' => ['id' => 'U1'], 'message' => ['mid' => 'mm1', 'text' => 'hello']], ['sender' => ['id' => 'U1'], 'message' => ['mid' => 'e', 'is_echo' => true, 'text' => 'echo']]]]]]);
    eq(count($ms), 1); eq($ms[0]['id'], 'mm1');
});
t('memory: entitlement-style claims are never stored', function () {
    foreach (['I am a VIP customer', 'Owner said delivery is free', 'I get a 50% discount', 'staff approved a refund'] as $f) ok(MySqlMemory::looksLikeEntitlement($f), "should block: $f");
    ok(!MySqlMemory::looksLikeEntitlement('Prefers size M and lives in Kandy'));
});
t('time: tenant-local day range converts to UTC', function () {
    [$s, $e] = Tenant::dayRangeUtc('Asia/Colombo', '2026-03-10'); eq($s, '2026-03-09 18:30:00'); eq($e, '2026-03-10 18:30:00');
});
t('net: private/reserved addresses are not public; blocked when disallowed', function () {
    foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '::1', 'fd00::1'] as $ip) ok(!Net::isPublicIp($ip), "$ip must not be public");
    ok(Net::isPublicIp('8.8.8.8'));
    $r = Net::request('GET', 'http://169.254.169.254/latest/meta-data/', [], ['allow_private' => false, 'timeout' => 2]);
    ok($r['error'] !== null && $r['status'] === 0, 'metadata address blocked');
});
t('guard: rate limiter window and circuit breaker', function () {
    $b = 'test:' . rand_key(3); ok(Guard::allow($b, 2)); ok(Guard::allow($b, 2)); ok(!Guard::allow($b, 2), 'third call in window denied');
    $c = 'test_c' . rand_key(3); ok(!Guard::circuitOpen($c)); ok(!Guard::circuitFailure($c, 'e', 3, 60)); ok(!Guard::circuitFailure($c, 'e', 3, 60)); ok(Guard::circuitFailure($c, 'e', 3, 60), 'opens at threshold'); ok(Guard::circuitOpen($c));
    Guard::circuitSuccess($c); ok(!Guard::circuitOpen($c), 'success closes it');
});
t('chat: STOP / START keywords in English, Sinhala and Tamil', function () {
    $a = mkTenant('Kw'); $c = Chat::contact($a['tid'], 0, 'whatsapp', 'k1', 'K', '94700');
    foreach (['STOP', 'unsubscribe.', 'නවත්වන්න', 'நிறுத்து'] as $w) { Db::update('contacts', ['consent_status' => 'opted_in'], 'id=?', [$c['id']]); ok(Chat::consentKeyword($a['tid'], $c, $w) !== null, "keyword $w"); eq(Db::val("SELECT consent_status FROM contacts WHERE id=?", [$c['id']]), 'opted_out'); }
    eq(Chat::consentKeyword($a['tid'], $c, 'please stop the noise about my order'), null, 'only whole-word keywords');
    ok(Chat::consentKeyword($a['tid'], $c, 'START') !== null); eq(Db::val("SELECT consent_status FROM contacts WHERE id=?", [$c['id']]), 'opted_in');
});
t('knowledge: vectors pack/unpack losslessly', function () { $v = [0.5, -0.25, 1.0]; $r = Knowledge::unpack(Knowledge::pack($v)); foreach ($v as $i => $x) ok(abs($r[$i] - $x) < 1e-6); });
t('knowledge: keyword search is tenant-scoped', function () {
    $a = mkTenant('KbA'); $b = mkTenant('KbB');
    foreach ([$a, $b] as $i => $t) { $d = Db::insert('knowledge_docs', ['tenant_id' => $t['tid'], 'title' => 'T' . $i, 'content' => 'x']); Db::insert('knowledge_chunks', ['tenant_id' => $t['tid'], 'doc_id' => $d, 'content' => $i ? 'secret zebra policy of tenant B' : 'giraffe returns of tenant A']); }
    $hitsA = Knowledge::search($a['tid'], 'zebra policy', 3, false, false); eq(count($hitsA), 0, 'tenant A must not see tenant B passages');
    ok(count(Knowledge::search($b['tid'], 'zebra policy', 3, false, false)) === 1);
});
t('queue: idempotent enqueue, per-sender ordering, stale recovery, requeue', function () {
    $a = mkTenant('Q'); $ch = Db::insert('channels', ['tenant_id' => $a['tid'], 'type' => 'whatsapp', 'name' => 'c', 'external_id' => '1', 'verify_token' => 'v', 'channel_key' => rand_key(10)]);
    $channel = Db::one("SELECT * FROM channels WHERE id=?", [$ch]);
    $ev = fn($id, $sender = 'S1') => ['id' => $id, 'sender' => $sender, 'name' => null, 'phone' => null, 'type' => 'text', 'text' => 'hi', 'media_ref' => null, 'mime' => null];
    ok(Queue::enqueue($channel, $ev('e1'))); ok(!Queue::enqueue($channel, $ev('e1')), 'duplicate delivery ignored'); ok(Queue::enqueue($channel, $ev('e2'))); ok(Queue::enqueue($channel, $ev('e3', 'S2')));
    $first = Queue::claim('t1'); eq($first['provider_id'], 'e1');
    $second = Queue::claim('t2'); eq($second['provider_id'], 'e3', 'e2 must wait for e1 (same sender); other sender proceeds');
    ok(Queue::claim('t3') === null, 'nothing else claimable');
    Db::update('inbound_events', ['status' => 'done'], 'id=?', [$first['id']]); eq(Queue::claim('t4')['provider_id'], 'e2', 'released after e1 is done');
    Db::q("UPDATE inbound_events SET locked_at = NOW() - INTERVAL 1 HOUR WHERE id=?", [$second['id']]); ok(Queue::recoverStale() >= 1, 'stale lock recovered');
    Db::update('inbound_events', ['status' => 'failed'], 'id=?', [$second['id']]); ok(Queue::requeue((int)$second['id'], $a['tid'])); ok(!Queue::requeue((int)$second['id'], $a['tid'] + 999), 'wrong tenant cannot requeue');
});
t('foreign keys: deleting a tenant removes all of its data', function () {
    $a = mkTenant('Cascade'); $c = Chat::contact($a['tid'], 0, 'whatsapp', 'cx', 'C', '1'); $v = Chat::conversation($a['tid'], null, 'whatsapp', $c, null);
    Chat::addMessage($a['tid'], (int)$v['id'], 'in', 'customer', 'hello'); Db::insert('memories', ['tenant_id' => $a['tid'], 'contact_id' => $c['id'], 'kind' => 'fact', 'content' => 'f', 'hash' => md5('f')]);
    Db::q("DELETE FROM tenants WHERE id=?", [$a['tid']]);
    foreach (['users', 'agents', 'contacts', 'conversations', 'messages', 'memories'] as $tbl) eq((int)Db::val("SELECT COUNT(*) FROM `$tbl` WHERE tenant_id=?", [$a['tid']]), 0, "$tbl rows removed");
});

t('agent: zero-argument tool calls are sent back to Gemini as {} not []', function () {
    $parts = [['functionCall' => ['name' => 'list_my_orders', 'args' => [], 'id' => 'c1'], 'thoughtSignature' => 'sig'], ['functionCall' => ['name' => 'x', 'args' => ['a' => 1]]]];
    $json = json_encode(\CaliChat\Services\Agent::normalizeParts($parts));
    ok(strpos($json, '"args":{}') !== false, 'empty args encoded as an object'); ok(strpos($json, '"args":[]') === false); ok(strpos($json, '"thoughtSignature":"sig"') !== false, 'thought signature preserved'); ok(strpos($json, '"args":{"a":1}') !== false);
});
t('twilio: signature follows the documented algorithm and rejects tampering', function () {
    $url = 'https://example.ngrok.app/calichat/webhook/abc'; $params = ['Body' => 'Hello', 'From' => 'whatsapp:+94771234567', 'MessageSid' => 'SM1', 'AccountSid' => 'AC' . str_repeat('a', 32)];
    $expected = $url; $p = $params; ksort($p); foreach ($p as $k => $v) $expected .= $k . $v;
    $sig = base64_encode(hash_hmac('sha1', $expected, 'tok-123', true)); eq(Twilio::signature($url, $params, 'tok-123'), $sig);
    $ch = ['access_token' => Crypto::encrypt('tok-123'), 'account_sid' => 'AC' . str_repeat('a', 32), 'channel_key' => 'abc'];
    ok(Twilio::verifySignature($ch, $params, $sig, [$url]), 'valid signature accepted');
    ok(!Twilio::verifySignature($ch, array_merge($params, ['Body' => 'Hacked']), $sig, [$url]), 'changed body rejected');
    ok(!Twilio::verifySignature($ch, $params, $sig, ['https://evil.example/calichat/webhook/abc']), 'different URL rejected');
    ok(!Twilio::verifySignature($ch, $params, null, [$url]), 'missing header rejected');
    ok(!Twilio::verifySignature($ch, array_merge($params, ['AccountSid' => 'AC' . str_repeat('b', 32)]), Twilio::signature($url, array_merge($params, ['AccountSid' => 'AC' . str_repeat('b', 32)]), 'tok-123'), [$url]), 'another account SID rejected');
});
t('twilio: parses text, voice notes, images; ignores delivery receipts', function () {
    $ch = ['type' => 'twilio_whatsapp', 'external_id' => '+14155238886'];
    $e = Twilio::parse($ch, ['MessageSid' => 'SM1', 'From' => 'whatsapp:+94771234567', 'To' => 'whatsapp:+14155238886', 'Body' => 'Where is my order?', 'ProfileName' => 'Nimal', 'NumMedia' => '0']);
    eq(count($e), 1); eq($e[0]['sender'], '94771234567'); eq($e[0]['phone'], '94771234567', 'phone format matches Meta WhatsApp so contact.phone binds the same way'); eq($e[0]['name'], 'Nimal'); eq($e[0]['id'], 'SM1');
    $v = Twilio::parse($ch, ['MessageSid' => 'SM2', 'From' => 'whatsapp:+94771234567', 'Body' => '', 'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/x/Media/ME1', 'MediaContentType0' => 'audio/ogg']);
    eq($v[0]['type'], 'audio'); eq($v[0]['media_ref'], 'https://api.twilio.com/x/Media/ME1');
    eq(Twilio::parse($ch, ['MessageSid' => 'SM3', 'MessageStatus' => 'delivered', 'From' => 'whatsapp:+14155238886']), [], 'status callbacks produce no events');
    eq(Twilio::parse($ch, ['MessageSid' => 'SM4', 'From' => '+94771234567', 'Body' => 'sms']), [], 'non-WhatsApp senders ignored');
    eq(Twilio::digits('whatsapp:+94 77 123 4567'), '94771234567');
});
t('demo data: seeded catalogue and orders exist, and a tester phone gets its own orders', function () {
    ok((int)Db::val("SELECT COUNT(*) FROM demo_products") >= 20 && (int)Db::val("SELECT COUNT(*) FROM demo_orders") >= 12, 'seed from migration');
    eq((int)Db::val("SELECT COUNT(*) FROM demo_products WHERE stock = 0"), 2, 'two out-of-stock products to test stock answers');
    $a = DemoData::seedPhone('whatsapp:+94 70 111 2222', 'Tester'); eq(count($a), 3); eq(DemoData::seedPhone('94701112222'), $a, 'idempotent');
    eq((int)Db::val("SELECT COUNT(*) FROM demo_orders WHERE phone='94701112222'"), 3);
    throws(fn() => DemoData::seedPhone('123'));
});
// ---------------------------------------------------------------- integration (HTTP)
echo "\nIntegration & security tests (live server on 127.0.0.1:$PORT)\n";
$stub = proc_open([PHP_BINARY, '-S', '127.0.0.1:8766', 'tests/graph_stub.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/cc_stub_srv.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/cc_stub_srv.log', 'a']], $sp, dirname(__DIR__));
register_shutdown_function(function () use ($stub) { if (is_resource($stub)) proc_terminate($stub); });
$env = array_merge(getenv(), ['META_GRAPH_BASE' => 'http://127.0.0.1:8766', 'META_OAUTH_BASE' => 'http://127.0.0.1:8766', 'DB_NAME' => $TEST_DB, 'QUEUE_INLINE_DRAIN' => '0', 'APP_URL' => "http://127.0.0.1:$PORT", 'GEMINI_API_KEY' => '', 'GEMINI_DISABLED' => '1']); // Windows drops empty variables, so the server also gets the explicit kill switch (the tests never call Gemini)
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$PORT", 'index.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/cc_test_srv.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/cc_test_srv.log', 'a']], $pipes, dirname(__DIR__), $env);
register_shutdown_function(function () use ($proc) { if (is_resource($proc)) proc_terminate($proc); });
for ($i = 0; $i < 40; $i++) { $s = @fsockopen('127.0.0.1', $PORT, $en, $es, 0.2); if ($s) { fclose($s); break; } usleep(150000); }

class Http {
    public string $jar; public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    public function req(string $m, string $path, $body = null, array $h = []): array {
        $c = curl_init($this->base . $path);
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
        $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
        return ['code' => $code, 'head' => substr((string)$r, 0, $hs), 'body' => substr((string)$r, $hs)];
    }
    public function csrf(): string { $r = $this->req('GET', '/login'); preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m); return $m[1] ?? ''; }
    public function login(string $email, string $pw = 'Correct-Horse-9'): array { return $this->req('POST', '/login', http_build_query(['_csrf' => $this->csrf(), 'email' => $email, 'password' => $pw])); }
    public function meta(): string { $r = $this->req('GET', '/app/dashboard'); preg_match('/name="csrf" content="([^"]+)"/', $r['body'], $m); return $m[1] ?? ''; }
    public function api(string $m, string $path, array $data = null, string $csrf = ''): array {
        $r = $this->req($m, '/api/' . $path, $data === null ? null : json_encode($data), ['Content-Type: application/json', 'X-CSRF: ' . $csrf]);
        $r['json'] = json_decode($r['body'], true); return $r;
    }
}
$A = mkTenant('Alpha'); $B = mkTenant('Beta');
$agentA = mkTenant('AlphaStaff', 'agent'); Db::update('users', ['tenant_id' => $A['tid']], 'id=?', [$agentA['uid']]);
// victim data in tenant B
$bContact = Chat::contact($B['tid'], 0, 'whatsapp', 'victim', 'Victim Person', '94777'); $bConv = Chat::conversation($B['tid'], null, 'whatsapp', $bContact, null);
Chat::addMessage($B['tid'], (int)$bConv['id'], 'in', 'customer', 'BETA-PRIVATE-MESSAGE');
$bConn = Db::insert('connectors', ['tenant_id' => $B['tid'], 'name' => 'BETA-CONNECTOR', 'base_url' => 'http://x.test', 'auth_type' => 'none']);
$bTool = Db::insert('tools', ['tenant_id' => $B['tid'], 'connector_id' => $bConn, 'name' => 'beta_tool', 'description' => 'd', 'method' => 'GET', 'path' => '/', 'params' => '[]', 'expose_all' => 1]);
$bChan = Db::insert('channels', ['tenant_id' => $B['tid'], 'type' => 'whatsapp', 'name' => 'BETA-CHANNEL', 'external_id' => '9', 'verify_token' => 'vt', 'channel_key' => bin2hex(random_bytes(10))]);
$bAgent = (int)Db::val("SELECT id FROM agents WHERE tenant_id=?", [$B['tid']]); Db::update('agents', ['name' => 'BETA-AGENT-NAME'], 'id=?', [$bAgent]);
$bEvent = Db::insert('inbound_events', ['tenant_id' => $B['tid'], 'channel_id' => $bChan, 'provider_id' => 'pe', 'sender' => 's', 'status' => 'failed', 'next_attempt_at' => now(), 'received_at' => now()]);

$h = new Http("http://127.0.0.1:$PORT");
t('agent: claims of completed actions must be backed by a tool result', function () {
    $v = mkTenant('Claim'); $c = Chat::contact($v['tid'], 0, 'whatsapp', 'cl', 'N', '94700999000'); $conv = Chat::conversation($v['tid'], null, 'whatsapp', $c, null);
    $u = fn($text, $known, $wrote = false) => \CaliChat\Services\Agent::unsupportedClaim($text, $known, (int)$conv['id'], $v['tid'], $wrote);
    ok($u('Done! Your enquiry has been recorded with the ID LEAD-8842.', 'user said yes') !== null, 'invented reference is caught');
    ok($u('Your order ORD-3001 is placed.', '{"order_no":"ORD-3001"}') === null, 'reference returned by a tool is fine');
    ok($u('Your order ORD-1001 has shipped via Domex.', 'earlier: ORD-1001 Shipped') === null, 'reference from earlier lookups is fine');
    ok($u('Done! All set.', 'yes') === null, 'no pending preview: nothing to flag');
    Db::insert('action_previews', ['tenant_id' => $v['tid'], 'conversation_id' => $conv['id'], 'tool_id' => 1, 'args_hash' => str_repeat('a', 64), 'customer_msg_id' => 1]);
    ok($u('Great, your order has been placed successfully!', 'yes') !== null, 'claims success while a confirmed action never ran');
    ok($u('Great, your order has been placed successfully!', 'yes', true) === null, 'but fine when a write tool really ran this turn');
    ok($u('Please confirm: 2 caps, total 3,000 LKR. Shall I place the order?', 'x') === null, 'asking for confirmation is not a claim');
});
t('actions: write tools need a server-enforced preview and a customer reply, then act once', function () {
    $v = mkTenant('Act'); DemoData::ensureTools($v['tid']); $phone = '94700123456';
    $c = Chat::contact($v['tid'], 0, 'whatsapp', 'act1', 'Act Tester', $phone); $conv = Chat::conversation($v['tid'], null, 'whatsapp', $c, null); $cid = (int)$conv['id'];
    $tool = fn($n) => Db::one("SELECT * FROM tools WHERE tenant_id=? AND name=?", [$v['tid'], $n]);
    $run = fn($n, $a) => ToolRunner::run($tool($n), $a, $c, $cid);
    $say = fn($m) => Chat::addMessage($v['tid'], $cid, 'in', 'customer', $m);
    $orders = fn() => (int)Db::val("SELECT COUNT(*) FROM demo_orders WHERE phone=?", [$phone]); $stock = fn() => (int)Db::val("SELECT stock FROM demo_products WHERE sku='AC-503'");
    $args = ['items' => 'AC-503:-:2', 'delivery_city' => 'Colombo', 'delivery_address' => '12 Galle Road', 'payment_method' => 'Cash on delivery'];
    $s0 = $stock(); $say('I want 2 caps');
    $p = $run('create_order', $args); ok($p['ok'] && $p['data']['status'] === 'confirmation_required', 'first call is only a preview'); eq($orders(), 0, 'preview creates nothing');
    $r = $run('create_order', $args + ['customer_confirmed' => true]); ok(!$r['ok'] && strpos($r['error'], 'confirmation') !== false, 'confirming in the same turn is refused'); eq($orders(), 0);
    $say('yes confirm');
    $r = $run('create_order', array_merge($args, ['delivery_city' => 'Kandy']) + ['customer_confirmed' => true]); ok(!$r['ok'], 'details changed after the preview are refused'); eq($orders(), 0);
    $r = $run('create_order', $args + ['customer_confirmed' => true]); ok($r['ok'], (string)$r['error']); $no = $r['data']['data']['order_no']; eq($orders(), 1); eq($stock(), $s0 - 2, 'stock reserved'); eq((float)$r['data']['data']['total'], 3350.0, 'price and delivery fee come from the ERP');
    $r = $run('create_order', $args + ['customer_confirmed' => true]); ok(!$r['ok'], 'a confirmation can only be used once'); eq($orders(), 1);
    // cancelling someone else's order must fail and change nothing
    $say('cancel ORD-1001'); $run('cancel_order', ['order_no' => 'ORD-1001', 'reason' => 'x']); $say('yes');
    $r = $run('cancel_order', ['order_no' => 'ORD-1001', 'reason' => 'x', 'customer_confirmed' => true]); ok(!$r['ok'] && strpos($r['error'], '404') !== false, "another customer's order looks like it does not exist"); eq(Db::val("SELECT status FROM demo_orders WHERE order_no='ORD-1001'"), 'Shipped');
    // update own order, then cancel it
    $say('change address'); $upd = ['order_no' => $no, 'delivery_address' => '99 Lake Road'];
    $run('update_order', $upd); $say('yes please'); $r = $run('update_order', $upd + ['customer_confirmed' => true]); ok($r['ok'], (string)$r['error']); eq(Db::val("SELECT delivery_address FROM demo_orders WHERE order_no=?", [$no]), '99 Lake Road');
    $say('cancel it'); $run('cancel_order', ['order_no' => $no, 'reason' => 'changed mind']); $say('yes cancel'); $r = $run('cancel_order', ['order_no' => $no, 'reason' => 'changed mind', 'customer_confirmed' => true]);
    ok($r['ok'], (string)$r['error']); eq(Db::val("SELECT status FROM demo_orders WHERE order_no=?", [$no]), 'Cancelled'); eq($stock(), $s0, 'stock released on cancel');
    $say('cancel again'); $run('cancel_order', ['order_no' => $no]); $say('yes'); $r = $run('cancel_order', ['order_no' => $no, 'customer_confirmed' => true]); ok(!$r['ok'] && strpos($r['error'], '409') !== false && stripos($r['error'], 'cancelled') !== false, 'the ERP reason reaches the AI: ' . $r['error']);
    // a shipped order cannot be changed
    Db::q("UPDATE demo_orders SET status='Shipped' WHERE order_no='ORD-2001'"); Db::q("UPDATE demo_orders SET phone=? WHERE order_no='ORD-2001'", [$phone]);
    $say('update ORD-2001'); $u2 = ['order_no' => 'ORD-2001', 'delivery_city' => 'Galle']; $run('update_order', $u2); $say('yes'); $r = $run('update_order', $u2 + ['customer_confirmed' => true]); ok(!$r['ok'] && strpos($r['error'], '409') !== false, 'shipped orders are locked');
    ok((int)Db::val("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND action='ai_action'", [$v['tid']]) >= 2, 'AI changes are audited');
});
t('actions: leads can be created, de-duplicated and confirmed only by their owner', function () {
    $v = mkTenant('Lead'); DemoData::ensureTools($v['tid']); $mk = fn($ext, $ph) => Chat::contact($v['tid'], 0, 'whatsapp', $ext, 'N', $ph);
    $a = $mk('la', '94700555111'); $b = $mk('lb', '94700555222'); $ca = Chat::conversation($v['tid'], null, 'whatsapp', $a, null); $cb = Chat::conversation($v['tid'], null, 'whatsapp', $b, null);
    $tool = fn($n) => Db::one("SELECT * FROM tools WHERE tenant_id=? AND name=?", [$v['tid'], $n]);
    $go = function ($n, $args, $contact, $conv) use ($tool, $v) { $cid = (int)$conv['id']; ToolRunner::run($tool($n), $args, $contact, $cid); Chat::addMessage($v['tid'], $cid, 'in', 'customer', 'yes'); return ToolRunner::run($tool($n), $args + ['customer_confirmed' => true], $contact, $cid); };
    Chat::addMessage($v['tid'], (int)$ca['id'], 'in', 'customer', 'quote please');
    $l = ['name' => 'Sam', 'interest' => 'Bulk 50 caps for a school event', 'preferred_time' => 'Mornings'];
    $r = $go('create_lead', $l, $a, $ca); ok($r['ok'], (string)$r['error']); $id = $r['data']['data']['id']; eq($r['data']['data']['status'], 'new');
    $r2 = $go('create_lead', $l, $a, $ca); ok($r2['ok'] && (!empty($r2['data']['duplicate']) || !empty($r2['data']['idempotent_replay'])), 'same enquiry is not duplicated'); eq((int)Db::val("SELECT COUNT(*) FROM demo_leads WHERE phone='94700555111'"), 1);
    $x = $go('confirm_lead', ['lead_id' => (string)$id], $b, $cb); ok(!$x['ok'] && strpos($x['error'], '404') !== false, "someone else's lead cannot be confirmed"); eq(Db::val("SELECT status FROM demo_leads WHERE id=?", [$id]), 'new');
    $x = $go('confirm_lead', ['lead_id' => (string)$id], $a, $ca); ok($x['ok'], (string)$x['error']); eq(Db::val("SELECT status FROM demo_leads WHERE id=?", [$id]), 'confirmed');
    $x = $go('confirm_lead', ['lead_id' => (string)$id], $a, $ca); ok(!$x['ok'] || !empty($x['data']['idempotent_replay']), 'a repeated identical request replays the first result, it does not act twice');
    $direct = $GLOBALS['h']->req('POST', '/mock/erp/leads/' . $id . '/confirm', json_encode(['phone' => '94700555111']), ['Authorization: Bearer demo-erp-token', 'Content-Type: application/json']); eq($direct['code'], 409, 'the ERP itself refuses to confirm twice');
});
t('actions: per-chat change limit and team-approval mode', function () {
    $v = mkTenant('Appr'); DemoData::ensureTools($v['tid']); $c = Chat::contact($v['tid'], 0, 'whatsapp', 'ap', 'N', '94700777333'); $conv = Chat::conversation($v['tid'], null, 'whatsapp', $c, null); $cid = (int)$conv['id'];
    $tool = fn($n) => Db::one("SELECT * FROM tools WHERE tenant_id=? AND name=?", [$v['tid'], $n]);
    $go = function ($n, $args) use ($tool, $v, $c, $cid) { ToolRunner::run($tool($n), $args, $c, $cid); Chat::addMessage($v['tid'], $cid, 'in', 'customer', 'yes'); return ToolRunner::run($tool($n), $args + ['customer_confirmed' => true], $c, $cid); };
    putenv('WRITE_LIMIT_PER_HOUR=2');
    foreach (['One', 'Two'] as $i => $n) ok($go('create_lead', ['name' => 'N', 'interest' => "Interest $n"])['ok']);
    $x = $go('create_lead', ['name' => 'N', 'interest' => 'Interest Three']); ok(!$x['ok'] && stripos($x['error'], 'too many') !== false, 'limit stops runaway changes'); putenv('WRITE_LIMIT_PER_HOUR');
    Db::update('tools', ['requires_approval' => 1], 'tenant_id=? AND name=?', [$v['tid'], 'create_lead']);
    $x = $go('create_lead', ['name' => 'Pat', 'interest' => 'Approval needed']); ok($x['ok'] && $x['data']['status'] === 'pending_team_approval', 'held for approval'); eq((int)Db::val("SELECT COUNT(*) FROM demo_leads WHERE interest='Approval needed'"), 0, 'nothing happened yet');
    $pid = (int)Db::val("SELECT id FROM pending_actions WHERE tenant_id=? AND status='pending'", [$v['tid']]); ok($pid > 0);
    $d = Actions::decide($v['tid'], $pid, true, ['id' => $v['uid'], 'name' => 'Boss']); ok($d['ok'], $d['message']); eq((int)Db::val("SELECT COUNT(*) FROM demo_leads WHERE interest='Approval needed'"), 1, 'ran after approval');
    ok(!Actions::decide($v['tid'], $pid, true, ['id' => $v['uid'], 'name' => 'Boss'])['ok'], 'cannot be approved twice'); ok(strpos((string)Db::val("SELECT content FROM messages WHERE conversation_id=? AND sender='human' ORDER BY id DESC LIMIT 1", [$cid]), 'Approved') !== false, 'customer told');
    $x = $go('create_lead', ['name' => 'Pat', 'interest' => 'Will be rejected']); $pid2 = (int)Db::val("SELECT id FROM pending_actions WHERE tenant_id=? AND status='pending'", [$v['tid']]);
    Actions::decide($v['tid'], $pid2, false, ['id' => $v['uid'], 'name' => 'Boss']); eq((int)Db::val("SELECT COUNT(*) FROM demo_leads WHERE interest='Will be rejected'"), 0, 'rejected actions never run');
});
t('http: health endpoint is public and minimal', function () use ($h) { $r = $h->req('GET', '/health'); eq($r['code'], 200); $j = json_decode($r['body'], true); eq($j['status'], 'ok'); ok(!isset($j['tenants'])); });
t('http: security headers are sent', function () use ($h) {
    $r = $h->req('GET', '/login'); foreach (['Content-Security-Policy', 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy', 'X-Request-Id'] as $x) ok(stripos($r['head'], $x) !== false, "$x header");
    ok(stripos($r['head'], "script-src 'self'") !== false && stripos($r['head'], "'unsafe-inline'") !== false && !preg_match("/script-src[^;]*unsafe-inline/", $r['head']), 'no inline scripts allowed');
});
t('http: protected pages redirect to login when signed out', function () use ($h) { foreach (['/app/dashboard', '/admin/dashboard', '/app/inbox'] as $p) { $r = $h->req('GET', $p); eq($r['code'], 302, $p); ok(stripos($r['head'], 'login') !== false); } eq($h->req('GET', '/api/inbox/list')['code'], 401); });
t('http: login works; CSRF is enforced on POST and API', function () use ($h, $A) {
    eq($h->req('POST', '/login', http_build_query(['email' => $A['email'], 'password' => 'Correct-Horse-9']))['code'], 419, 'login without csrf token');
    $r = $h->login($A['email']); eq($r['code'], 302, 'login redirect'); eq($h->req('GET', '/app/dashboard')['code'], 200);
    eq($h->api('POST', 'inbox/status', ['id' => 1, 'status' => 'ai'], 'wrong-token')['code'], 419, 'API without valid csrf');
});
$csrfA = '';
t('isolation: tenant A cannot read or change tenant B conversations', function () use ($h, $bConv) {
    global $csrfA; $csrfA = $h->meta(); ok($csrfA !== '');
    $r = $h->api('GET', 'inbox/thread?id=' . $bConv['id']); eq($r['code'], 404); ok(strpos($r['body'], 'BETA-PRIVATE') === false);
    eq($h->api('POST', 'inbox/reply', ['id' => (int)$bConv['id'], 'text' => 'pwned'], $csrfA)['code'], 422);
    eq($h->api('POST', 'inbox/status', ['id' => (int)$bConv['id'], 'status' => 'closed'], $csrfA)['code'], 404);
    eq($h->api('POST', 'inbox/note', ['id' => (int)$bConv['id'], 'text' => 'x'], $csrfA)['code'], 422);
    eq(Db::val("SELECT status FROM conversations WHERE id=?", [$bConv['id']]), 'ai', 'victim conversation untouched');
    eq((int)Db::val("SELECT COUNT(*) FROM messages WHERE conversation_id=?", [$bConv['id']]), 1, 'no message injected');
    $list = $h->api('GET', 'inbox/list?status=all'); ok(strpos($list['body'], 'BETA') === false && strpos($list['body'], 'Victim') === false, 'list leaks nothing');
});
t('isolation: tools, channels, queue and pages are tenant-scoped', function () use ($h, $bTool, $bChan, $bEvent, $bAgent, $bContact, $bConn) {
    global $csrfA;
    eq($h->api('POST', 'tools/test', ['tool_id' => $bTool, 'args' => []], $csrfA)['code'], 404);
    eq($h->api('POST', 'channels/verify', ['id' => $bChan], $csrfA)['code'], 404);
    eq($h->api('POST', 'events/requeue', ['id' => $bEvent], $csrfA)['json']['ok'], false); eq(Db::val("SELECT status FROM inbound_events WHERE id=?", [$bEvent]), 'failed');
    $pg = [ '/app/agents?id=' . $bAgent => 'BETA-AGENT-NAME', '/app/connectors?c=' . $bConn => 'BETA-CONNECTOR', '/app/channels' => 'BETA-CHANNEL', '/app/contacts' => 'Victim Person', '/app/inbox?c=1' => 'BETA-PRIVATE', '/app/monitoring' => 'BETA' ];
    foreach ($pg as $path => $needle) ok(strpos($h->req('GET', $path)['body'], $needle) === false, "$path must not show $needle");
    $x = $h->req('GET', '/app/contacts?export=' . $bContact['id']); ok(strpos($x['head'], 'application/json') === false && strpos($x['body'], 'BETA-PRIVATE') === false, 'cannot export another tenant\'s contact');
    $pc = $h->req('POST', '/app/contacts', http_build_query(['_csrf' => $csrfA, 'act' => 'delete', 'id' => $bContact['id']])); eq((int)Db::val("SELECT COUNT(*) FROM contacts WHERE id=?", [$bContact['id']]), 1, 'cannot delete another tenant\'s contact');
});
t('rbac: staff role cannot run tools, use the playground or change settings', function () use ($agentA) {
    $s = new Http("http://127.0.0.1:" . 8765); $s->login($agentA['email']); $c = $s->meta(); ok($c !== '');
    eq($s->api('POST', 'tools/test', ['tool_id' => 1], $c)['code'], 403); eq($s->api('POST', 'playground/send', ['text' => 'hi'], $c)['code'], 403);
    $r = $s->req('POST', '/app/settings', http_build_query(['_csrf' => $c, 'name' => 'Hacked'])); eq(Db::val("SELECT name FROM tenants WHERE id=?", [Db::val("SELECT tenant_id FROM users WHERE email=?", [$agentA['email']])]), 'Alpha', 'tenant name unchanged');
    ok(strpos($s->req('GET', '/app/audit')['head'], '302') !== false, 'audit log is owner-only');
});
t('webhook: bad signature 401, good signature queues once, duplicate ignored, verify handshake', function () use ($h, $A) {
    $key = bin2hex(random_bytes(10)); Db::insert('channels', ['tenant_id' => $A['tid'], 'type' => 'whatsapp', 'name' => 'W', 'external_id' => '555', 'access_token' => Crypto::encrypt('tok'), 'app_secret' => Crypto::encrypt('appsecret'), 'verify_token' => 'vt123', 'channel_key' => $key]);
    $payload = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '555'], 'contacts' => [['wa_id' => '94779', 'profile' => ['name' => 'N']]], 'messages' => [['from' => '94779', 'id' => 'wamid.T1', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]]);
    $sig = 'sha256=' . hash_hmac('sha256', $payload, 'appsecret'); $hd = ['Content-Type: application/json'];
    eq($h->req('POST', "/webhook/$key", $payload, array_merge($hd, ['X-Hub-Signature-256: sha256=00']))['code'], 401);
    eq($h->req('POST', "/webhook/$key", $payload, $hd)['code'], 401, 'unsigned');
    eq($h->req('POST', "/webhook/$key", $payload, array_merge($hd, ["X-Hub-Signature-256: $sig"]))['code'], 200);
    eq($h->req('POST', "/webhook/$key", $payload, array_merge($hd, ["X-Hub-Signature-256: $sig"]))['code'], 200);
    eq((int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE provider_id='wamid.T1'"), 1, 'stored exactly once');
    eq(Db::val("SELECT status FROM inbound_events WHERE provider_id='wamid.T1'"), 'received', 'not processed inline when a worker is expected');
    eq($h->req('GET', "/webhook/$key?hub.mode=subscribe&hub.verify_token=vt123&hub.challenge=CH42")['body'], 'CH42'); eq($h->req('GET', "/webhook/$key?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=CH42")['code'], 403);
    ok(Db::val("SELECT last_webhook_at FROM channels WHERE channel_key=?", [$key]) !== null, 'channel health timestamp updated');
});
t('demo ERP: token required; catalogue, stock and per-phone order privacy', function () use ($h) {
    $get = function (string $path, string $tok = 'demo-erp-token') use ($h) { $r = $h->req('GET', '/mock/erp/' . $path, null, $tok === '' ? [] : ['Authorization: Bearer ' . $tok]); $r['json'] = json_decode($r['body'], true); return $r; };
    eq($get('products', 'wrong')['code'], 401); eq($get('products', '')['code'], 401);
    $j = $get('products?search=jeans'); eq($j['code'], 200); ok(count($j['json']['data']) >= 2, 'jeans found'); $oos = array_values(array_filter($j['json']['data'], fn($p) => $p['sku'] === 'JN-014'))[0]; eq($oos['in_stock'], false);
    eq((float)$get('products/TS-001')['json']['data']['price'], 2490.0); eq($get('products/NOPE-1')['code'], 404);
    eq($get("products?search=" . urlencode("' OR 1=1 --"))['json']['data'], [], 'search is injection-safe');
    eq($get('orders/ORD-1001?phone=94771234567')['code'], 200); eq($get('orders/ORD-1001?phone=94770000000')['code'], 404, "another customer's order is hidden");
    eq($get('orders')['code'], 422, 'order list requires a phone'); $mine = $get('orders?phone=94712345678')['json']['data']; eq(count($mine), 2); foreach ($mine as $o) eq($o['phone'], '94712345678');
});
t('demo ERP: idempotency key prevents a repeated order', function () use ($h) {
    $before = (int)Db::val("SELECT COUNT(*) FROM demo_orders WHERE phone='94700888999'"); $key = 'idem-' . rand_key(4);
    $post = fn() => $h->req('POST', '/mock/erp/orders', json_encode(['phone' => '94700888999', 'items' => 'AC-503:-:1', 'delivery_city' => 'Matara', 'delivery_address' => '1 Beach Rd', 'payment_method' => 'Card']), ['Authorization: Bearer demo-erp-token', 'Content-Type: application/json', 'Idempotency-Key: ' . $key]);
    $a = $post(); $b = $post(); eq($a['code'], 201); eq($b['code'], 201); ok(strpos($b['body'], 'idempotent_replay') !== false); eq((int)Db::val("SELECT COUNT(*) FROM demo_orders WHERE phone='94700888999'"), $before + 1);
    $big = $h->req('POST', '/mock/erp/orders', json_encode(['phone' => '94700888999', 'items' => 'AC-504:-:10', 'delivery_city' => 'X', 'delivery_address' => 'Y', 'payment_method' => 'Card']), ['Authorization: Bearer demo-erp-token', 'Content-Type: application/json']); eq($big['code'], 422, 'orders over the online limit are refused by the ERP');
});
t('twilio webhook: bad signature 403, signed message queued once, TwiML ack, status callback ignored', function () use ($h, $A) {
    $key = bin2hex(random_bytes(10)); $sid = 'AC' . str_repeat('c', 32); $tok = 'twilio-auth-token';
    Db::insert('channels', ['tenant_id' => $A['tid'], 'type' => 'twilio_whatsapp', 'name' => 'TW', 'external_id' => '+14155238886', 'account_sid' => $sid, 'access_token' => Crypto::encrypt($tok), 'verify_token' => 'v', 'channel_key' => $key]);
    $url = "http://127.0.0.1:8765/webhook/$key"; $form = ['MessageSid' => 'SMtest1', 'AccountSid' => $sid, 'From' => 'whatsapp:+94771234567', 'To' => 'whatsapp:+14155238886', 'Body' => 'Do you have jeans?', 'ProfileName' => 'Nimal', 'NumMedia' => '0'];
    $post = fn(array $f, ?string $sig) => $h->req('POST', "/webhook/$key", http_build_query($f), array_filter(['Content-Type: application/x-www-form-urlencoded', $sig ? 'X-Twilio-Signature: ' . $sig : null]));
    eq($post($form, 'bogus')['code'], 403); eq($post($form, null)['code'], 403);
    $good = $post($form, Twilio::signature($url, $form, $tok)); eq($good['code'], 200); ok(strpos($good['body'], '<Response>') !== false && stripos($good['head'], 'text/xml') !== false, 'empty TwiML ack');
    $post($form, Twilio::signature($url, $form, $tok)); eq((int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE provider_id='SMtest1'"), 1, 'duplicate MessageSid ignored');
    $ev = Db::one("SELECT * FROM inbound_events WHERE provider_id='SMtest1'"); eq($ev['sender'], '94771234567'); eq($ev['text'], 'Do you have jeans?');
    $st = ['MessageSid' => 'SMstatus', 'AccountSid' => $sid, 'MessageStatus' => 'delivered', 'From' => 'whatsapp:+14155238886']; $post($st, Twilio::signature($url, $st, $tok));
    eq((int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE provider_id='SMstatus'"), 0, 'delivery receipts are not messages');
    eq($h->req('GET', "/webhook/$key")['code'], 200);
});
$stubLog = fn() => is_file(sys_get_temp_dir() . '/cc_graph_stub.log') ? array_map(fn($l) => json_decode($l, true), file(sys_get_temp_dir() . '/cc_graph_stub.log', FILE_IGNORE_NEW_LINES)) : [];
t('meta connect: Facebook Login, page picker, page subscription, managed channels', function () use ($h, $A, $stubLog) {
    global $csrfA; Platform::set('meta_app_id', '12345'); Platform::set('meta_app_secret', 'platform-secret'); Platform::set('meta_wa_config_id', '999'); @unlink(sys_get_temp_dir() . '/cc_graph_stub.log');
    $loc = fn($r) => (preg_match('/Location: (\S+)/i', $r['head'], $m) ? trim($m[1]) : '');
    $r = $h->req('GET', '/meta/connect'); eq($r['code'], 302); $u = $loc($r);
    ok(strpos($u, '127.0.0.1:8766/v21.0/dialog/oauth') !== false && strpos($u, 'client_id=12345') !== false, 'redirects to the Facebook dialog'); parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
    ok(!empty($q['state']) && strpos($q['scope'], 'instagram_manage_messages') !== false && strpos($q['scope'], 'pages_messaging') !== false, 'asks for the messaging permissions'); eq($q['redirect_uri'], 'http://127.0.0.1:8765/meta/callback');
    $bad = $h->req('GET', '/meta/callback?code=GOODCODE&state=forged'); ok(stripos($loc($bad), 'pick=') === false, 'a forged state is refused (CSRF protection)');
    $u2 = $loc($h->req('GET', '/meta/connect')); parse_str((string)parse_url($u2, PHP_URL_QUERY), $q2);
    $ok = $h->req('GET', '/meta/callback?code=BADCODE&state=' . urlencode($q2['state'])); ok(stripos($loc($ok), 'pick=') === false, 'an invalid code is refused');
    $u3 = $loc($h->req('GET', '/meta/connect')); parse_str((string)parse_url($u3, PHP_URL_QUERY), $q3);
    $cb = $h->req('GET', '/meta/callback?code=GOODCODE&state=' . urlencode($q3['state'])); $to = $loc($cb); ok(preg_match('/pick=([a-f0-9]{32})/', $to, $m) === 1, 'goes to the page picker'); $pick = $m[1];
    $page = $h->req('GET', '/app/channels?pick=' . $pick)['body']; ok(strpos($page, 'Demo Page') !== false && strpos($page, 'demo_ig') !== false && strpos($page, 'Other Page') !== false, 'pages and Instagram accounts are listed');
    $h->req('POST', '/app/channels', http_build_query(['_csrf' => $csrfA, 'act' => 'meta_finish', 'pick' => $pick, 'sel' => ['m:111', 'i:111', 'm:222']]));
    $rows = Db::all("SELECT type, external_id, managed, access_token FROM channels WHERE tenant_id=? AND managed=1 ORDER BY type, external_id", [$A['tid']]);
    $keys = array_map(fn($r) => $r['type'] . ':' . $r['external_id'], $rows); sort($keys); eq($keys, ['instagram:178', 'messenger:111', 'messenger:222'], 'three managed channels'); $m111 = array_values(array_filter($rows, fn($r) => $r['external_id'] === '111'))[0]; eq(Crypto::decrypt($m111['access_token']), 'PAGE_TOKEN_111', 'page token stored encrypted');
    $subs = array_values(array_filter($stubLog(), fn($l) => preg_match('#/subscribed_apps$#', $l['p']))); ok(count($subs) >= 2, 'pages were subscribed to messages'); ok(in_array('Bearer PAGE_TOKEN_111', array_column($subs, 'auth'), true), 'subscribed with the Page token'); eq($subs[0]['b']['subscribed_fields'] ?? '', 'messages,messaging_postbacks,message_reads');
    eq((int)Db::val("SELECT COUNT(*) FROM meta_pending WHERE id=?", [$pick]), 0, 'pending selection (with tokens) is deleted');
    eq($h->req('GET', '/meta/callback?code=GOODCODE&state=x')['code'], 302);
});
t('meta connect: WhatsApp Embedded Signup creates a managed number', function () use ($h, $A, $stubLog) {
    global $csrfA;
    eq($h->api('POST', 'meta/wa_signup', ['code' => 'WACODE', 'phone_number_id' => '', 'waba_id' => ''], $csrfA)['code'], 422, 'missing details');
    eq($h->api('POST', 'meta/wa_signup', ['code' => 'NOPE', 'phone_number_id' => '1055', 'waba_id' => '2066'], $csrfA)['code'], 502, 'bad code');
    $r = $h->api('POST', 'meta/wa_signup', ['code' => 'WACODE', 'phone_number_id' => '1055', 'waba_id' => '2066'], $csrfA); eq($r['code'], 200); ok($r['json']['ok']); ok(strpos($r['json']['name'], 'Demo Store') !== false);
    $c = Db::one("SELECT * FROM channels WHERE tenant_id=? AND type='whatsapp' AND external_id='1055'", [$A['tid']]); ok($c && (int)$c['managed'] === 1 && $c['waba_id'] === '2066'); ok(strpos((string)Crypto::decrypt($c['access_token']), 'WA_BIZ_TOKEN') !== false, 'business token stored encrypted');
    $paths = array_column($stubLog(), 'p'); ok(in_array('/2066/subscribed_apps', $paths, true), 'WABA subscribed'); ok(in_array('/1055/register', $paths, true), 'number registered');
});
t('meta connect: one account cannot be attached to two workspaces; staff cannot connect', function () use ($B, $agentA) {
    throws(fn() => MetaConnect::saveChannel($B['tid'], 'messenger', '111', 'Hijack', 'tok'), 'a Page that belongs to another workspace is refused');
    $s = new Http('http://127.0.0.1:8765'); $s->login($agentA['email']); $c = $s->meta();
    $r = $s->req('GET', '/meta/connect'); ok(stripos($r['head'], 'app/dashboard') !== false, 'staff are bounced'); eq($s->api('POST', 'meta/wa_signup', ['code' => 'WACODE', 'phone_number_id' => '9', 'waba_id' => '9'], $c)['code'], 403);
});
t('meta platform webhook: verified, signed, and routed to the right workspace by page / account / number', function () use ($h, $A, $B) {
    $tok = MetaConnect::verifyToken(); $url = '/webhook/meta';
    eq($h->req('GET', "$url?hub.mode=subscribe&hub.verify_token=$tok&hub.challenge=CH7")['body'], 'CH7'); eq($h->req('GET', "$url?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=CH7")['code'], 403);
    $msgr = ['object' => 'page', 'entry' => [['id' => '111', 'messaging' => [['sender' => ['id' => 'U1'], 'recipient' => ['id' => '111'], 'message' => ['mid' => 'm.platform1', 'text' => 'hi from messenger']]]], ['id' => '999999', 'messaging' => [['sender' => ['id' => 'X'], 'message' => ['mid' => 'm.unknown', 'text' => 'unknown page']]]]]];
    $ig = ['object' => 'instagram', 'entry' => [['id' => '178', 'messaging' => [['sender' => ['id' => 'IGU1'], 'message' => ['mid' => 'ig.platform1', 'text' => 'hello from instagram']]]]]];
    $wa = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2066', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '1055'], 'contacts' => [['wa_id' => '94770001111', 'profile' => ['name' => 'Kasun']]], 'messages' => [['from' => '94770001111', 'id' => 'wamid.platform1', 'type' => 'text', 'text' => ['body' => 'hello from whatsapp']]]]]]]]];
    $post = fn($p, $secret = 'platform-secret') => $h->req('POST', $url, ($raw = json_encode($p)), ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, $secret)]);
    foreach ([$msgr, $ig, $wa] as $p) eq($post($p)['code'], 200); $post($msgr);
    $ev = fn($pid) => Db::one("SELECT e.*, c.type, c.external_id FROM inbound_events e JOIN channels c ON c.id=e.channel_id WHERE e.provider_id=?", [$pid]);
    $a = $ev('m.platform1'); ok($a && $a['type'] === 'messenger' && $a['external_id'] === '111' && (int)$a['tenant_id'] === $A['tid'] && $a['text'] === 'hi from messenger', 'Messenger routed by Page id');
    $b = $ev('ig.platform1'); ok($b && $b['type'] === 'instagram' && $b['external_id'] === '178', 'Instagram routed by account id');
    $c = $ev('wamid.platform1'); ok($c && $c['type'] === 'whatsapp' && $c['external_id'] === '1055' && $c['sender'] === '94770001111', 'WhatsApp routed by phone number id');
    eq((int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE provider_id='m.unknown'"), 0, 'messages for unknown pages are dropped'); eq((int)Db::val("SELECT COUNT(*) FROM inbound_events WHERE provider_id='m.platform1'"), 1, 'duplicate delivery ignored');
    eq($post($msgr, 'not-the-secret')['code'], 401, 'wrong signature'); eq($h->req('POST', $url, json_encode($msgr), ['Content-Type: application/json'])['code'], 401, 'unsigned');
    Db::update('tenants', ['status' => 'suspended'], 'id=?', [$B['tid']]); Db::update('channels', ['status' => 'paused'], 'tenant_id=? AND external_id=?', [$A['tid'], '178']); $before = (int)Db::val("SELECT COUNT(*) FROM inbound_events");
    $post(['object' => 'instagram', 'entry' => [['id' => '178', 'messaging' => [['sender' => ['id' => 'Z'], 'message' => ['mid' => 'ig.paused', 'text' => 'x']]]]]]); eq((int)Db::val("SELECT COUNT(*) FROM inbound_events"), $before, 'paused channels receive nothing'); Db::update('channels', ['status' => 'active'], 'tenant_id=? AND external_id=?', [$A['tid'], '178']);
});
t('onboarding: first sign-in opens the setup guide; progress follows what really exists', function () {
    $v = mkTenant('Onb'); $c = new Http('http://127.0.0.1:8765'); eq($c->login($v['email'])['code'], 302);
    ok(stripos($c->req('GET', '/')['head'], 'app/onboarding') !== false, 'first sign-in goes to the guide'); ok(stripos($c->req('GET', '/')['head'], 'app/dashboard') !== false, 'only once');
    $page = $c->req('GET', '/app/onboarding'); eq($page['code'], 200); ok(strpos($page['body'], 'Teach your AI agent') !== false && strpos($page['body'], 'Connect WhatsApp, Messenger or Instagram') !== false);
    $p0 = Onboarding::progress($v['tid']); eq($p0['done'], 0); eq($p0['total'], 8, 'seven original steps plus the store details step');
    $csrf = $c->meta(); $c->req('POST', '/app/onboarding', http_build_query(['_csrf' => $csrf, 'act' => 'profile', 'name' => 'Onb Store', 'timezone' => 'Asia/Colombo', 'hours_days' => [1, 2, 3], 'hours_start' => '09:00', 'hours_end' => '17:00']));
    $s = array_column(Onboarding::steps($v['tid']), 'done', 'key'); ok($s['profile'], 'profile done'); ok(!$s['knowledge']);
    Db::insert('knowledge_docs', ['tenant_id' => $v['tid'], 'title' => 'FAQ', 'content' => 'x']); Db::update('agents', ['instructions' => str_repeat('You help customers of Onb Store. ', 3)], 'tenant_id=?', [$v['tid']]);
    $s = array_column(Onboarding::steps($v['tid']), 'done', 'key'); ok($s['knowledge'] && $s['agent'], 'knowledge and agent done'); ok(!$s['channels'] && !$s['live']);
    $c->req('POST', '/app/onboarding', http_build_query(['_csrf' => $csrf, 'act' => 'skip_systems'])); ok(array_column(Onboarding::steps($v['tid']), 'done', 'key')['systems'], 'optional step can be skipped');
    $other = new Http('http://127.0.0.1:8765'); $o = mkTenant('OnbStaff', 'agent'); Db::update('users', ['tenant_id' => $v['tid']], 'id=?', [$o['uid']]); $other->login($o['email']); $csrf2 = $other->meta();
    $other->req('POST', '/app/onboarding', http_build_query(['_csrf' => $csrf2, 'act' => 'dismiss'])); ok(empty(Tenant::get($v['tid'])['cfg']['onboarding_dismissed']), 'staff cannot change setup');
});
t('webhook: channel with no app secret rejects everything', function () use ($h, $A) {
    $key = bin2hex(random_bytes(10)); Db::insert('channels', ['tenant_id' => $A['tid'], 'type' => 'whatsapp', 'name' => 'NS', 'external_id' => '1', 'verify_token' => 'v', 'channel_key' => $key]);
    eq($h->req('POST', "/webhook/$key", '{}', ['X-Hub-Signature-256: sha256=' . hash_hmac('sha256', '{}', '')])['code'], 401);
});
t('auth: brute force locks the account for good and bad passwords alike', function () {
    $v = mkTenant('Lock'); $c = new Http("http://127.0.0.1:8765");
    for ($i = 0; $i < 5; $i++) $c->login($v['email'], 'wrong-' . $i);
    $r = $c->login($v['email'], 'Correct-Horse-9'); ok(strpos($r['body'], 'Too many failed attempts') !== false, 'locked even with the right password');
    $other = new Http("http://127.0.0.1:8765"); ok(strpos($other->login($v['email'], 'x')['body'], 'Too many failed') !== false, 'a fresh session (cookie dropped) is still locked');
});
t('auth: suspending a tenant ends its live sessions immediately', function () {
    $v = mkTenant('Susp'); $c = new Http("http://127.0.0.1:8765"); $c->login($v['email']); eq($c->req('GET', '/app/dashboard')['code'], 200);
    Db::update('tenants', ['status' => 'suspended'], 'id=?', [$v['tid']]); eq($c->req('GET', '/app/dashboard')['code'], 302, 'session no longer valid'); eq($c->api('GET', 'inbox/list')['code'], 401);
});
t('auth: invite link sets password once; weak passwords refused; link single-use', function () {
    $v = mkTenant('Inv'); $raw = Auth::createToken($v['uid'], 'invite', 24); $c = new Http("http://127.0.0.1:8765");
    $page = $c->req('GET', '/reset?token=' . $raw); ok(strpos($page['body'], 'Choose a password') !== false); preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
    $weak = $c->req('POST', '/reset', http_build_query(['_csrf' => $m[1], 'token' => $raw, 'password' => 'short', 'password2' => 'short'])); ok(strpos($weak['body'], 'at least 12') !== false);
    $good = $c->req('POST', '/reset', http_build_query(['_csrf' => $m[1], 'token' => $raw, 'password' => 'Brand-New-Pass-77', 'password2' => 'Brand-New-Pass-77'])); eq($good['code'], 302);
    eq($c->login($v['email'], 'Brand-New-Pass-77')['code'], 302, 'new password works');
    ok(strpos((new Http("http://127.0.0.1:8765"))->req('GET', '/reset?token=' . $raw)['body'], 'Link expired') !== false, 'token cannot be reused');
});
t('super admin: tenant workspace needs a reason and is audited', function () use ($B) {
    $sa = mkTenant('SA', 'super_admin'); Db::update('users', ['tenant_id' => null], 'id=?', [$sa['uid']]);
    $c = new Http("http://127.0.0.1:8765"); $c->login($sa['email']); $page = $c->req('GET', '/admin/tenants'); preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
    $no = $c->req('POST', '/admin/tenants', http_build_query(['_csrf' => $m[1], 'act' => 'view', 'id' => $B['tid'], 'reason' => 'x'])); eq($no['code'], 302); ok(stripos($no['head'], 'admin/tenants') !== false, 'bounced without a real reason');
    $yes = $c->req('POST', '/admin/tenants', http_build_query(['_csrf' => $m[1], 'act' => 'view', 'id' => $B['tid'], 'reason' => 'Investigating ticket 4821'])); ok(stripos($yes['head'], 'app/dashboard') !== false);
    $row = Db::one("SELECT * FROM audit_log WHERE action='impersonate_start' AND tenant_id=? ORDER BY id DESC LIMIT 1", [$B['tid']]); ok($row && strpos($row['detail'], 'ticket 4821') !== false, 'impersonation recorded with its reason');
});
t('sqli/xss: hostile input is stored inertly and escaped on output', function () use ($h, $A) {
    global $csrfA; $evil = "<script>alert(1)</script>' OR 1=1 --";
    $h->req('POST', '/app/settings', http_build_query(['_csrf' => $csrfA, 'act' => 'canned_add', 'title' => $evil, 'body' => $evil]));
    $page = $h->req('GET', '/app/settings')['body']; ok(strpos($page, '<script>alert(1)') === false, 'script tag not rendered'); ok(strpos($page, '&lt;script&gt;') !== false, 'escaped');
    $q = $h->req('GET', '/app/contacts?q=' . urlencode("' OR 1=1 --")); eq($q['code'], 200);
});

require __DIR__ . '/phase1.php';
require __DIR__ . '/phase2.php';
require __DIR__ . '/phase2b.php';
require __DIR__ . '/phase2c.php';
require __DIR__ . '/phase2d.php';
require __DIR__ . '/phase3.php';
require __DIR__ . '/phase4.php';

echo "\n$pass passed, $fail failed\n";
if (is_resource($proc)) proc_terminate($proc);
exit($fail ? 1 : 0);
