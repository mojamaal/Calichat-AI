<?php
/** MANUAL live test (needs a real GEMINI_API_KEY, Apache running, and `php cli/worker.php` running in another window). Sends signed Twilio-style webhooks to the demo tenant and prints the AI's replies. Clean up afterwards: delete the 'E2E' channel and demo orders created by AI. */
require dirname(__DIR__, 2) . '/config/bootstrap.php';
use CaliChat\Core\{Crypto, Db};
use CaliChat\Services\Twilio;

$key = bin2hex(random_bytes(10)); $sid = 'AC' . bin2hex(random_bytes(16)); $tok = 'fake-auth-token'; $phone = '94771234567';
Db::q("DELETE FROM channels WHERE name='CRUD-E2E'");
$ch = Db::insert('channels', ['tenant_id' => 1, 'type' => 'twilio_whatsapp', 'name' => 'CRUD-E2E', 'external_id' => '+14155238886', 'account_sid' => $sid, 'access_token' => Crypto::encrypt($tok), 'verify_token' => 'v', 'channel_key' => $key]);
$url = rtrim((string)env('APP_URL', 'http://localhost/calichat'), '/') . "/webhook/$key"; $n = 0; $run = bin2hex(random_bytes(3));
$say = function (string $text) use ($url, $sid, $tok, $phone, $ch, &$n) {
    $id = 'SMcrud' . $GLOBALS['run'] . '_' . (++$n);
    $f = ['MessageSid' => $id, 'AccountSid' => $sid, 'From' => "whatsapp:+$phone", 'To' => 'whatsapp:+14155238886', 'Body' => $text, 'ProfileName' => 'Nimal Perera', 'NumMedia' => '0'];
    $c = curl_init($url); curl_setopt_array($c, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query($f), CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'X-Twilio-Signature: ' . Twilio::signature($url, $f, $tok)]]);
    curl_exec($c);
    $t0 = time();
    while (time() - $t0 < 120) { $s = Db::val("SELECT status FROM inbound_events WHERE provider_id=? AND channel_id=?", [$id, $ch]); if (in_array($s, ['done', 'failed', 'skipped'], true)) break; sleep(2); }
    $m = Db::one("SELECT m.content, m.meta FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.channel_id=? AND m.sender IN ('ai') ORDER BY m.id DESC LIMIT 1", [$ch]);
    $tools = json_decode((string)($m['meta'] ?? ''), true)['tools'] ?? [];
    printf("CUSTOMER: %s\n  AI [%s]: %s\n\n", $text, implode(',', $tools), str_replace("\n", "\n      ", (string)($m['content'] ?? '(no reply)')));
};
$say("Hi, I'd like to buy 2 black Cotton Caps please. Deliver to 45 Temple Road, Colombo 05. I'll pay cash on delivery.");
$say("Yes, please go ahead and place the order.");
$say("Actually, can you change the delivery address to 12 Lake Road, Kandy?");
$say("Yes, that's correct.");
$say("Sorry, I changed my mind. Please cancel that order.");
$say("Yes, cancel it.");
$say("I also need 30 polo shirts for my company. Can someone call me on weekday evenings? My name is Nimal.");
$say("Yes, please record that.");
echo "---- ERP state ----\n";
foreach (Db::all("SELECT order_no, status, delivery_city, delivery_address, total FROM demo_orders WHERE internal_cost_note LIKE 'created by AI%' OR internal_cost_note LIKE '%cancel reason%' ORDER BY order_no DESC LIMIT 3") as $o) echo json_encode($o), "\n";
foreach (Db::all("SELECT id, name, interest, preferred_time, status FROM demo_leads WHERE phone=? ORDER BY id DESC LIMIT 2", [$phone]) as $l) echo json_encode($l), "\n";
echo "AI cap stock now: ", Db::val("SELECT stock FROM demo_products WHERE sku='AC-503'"), "\n";
