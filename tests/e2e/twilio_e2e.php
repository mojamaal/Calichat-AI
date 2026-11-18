<?php
/** MANUAL live test (needs a real GEMINI_API_KEY, Apache running, and `php cli/worker.php` running in another window). Sends signed Twilio-style webhooks to the demo tenant and prints the AI's replies. Clean up afterwards: delete the 'E2E' channel and demo orders created by AI. */
require dirname(__DIR__, 2) . '/config/bootstrap.php';
use CaliChat\Core\{Crypto, Db};
use CaliChat\Services\{Queue, Twilio};

$key = bin2hex(random_bytes(10)); $sid = 'AC' . bin2hex(random_bytes(16)); $tok = 'fake-auth-token';
Db::q("DELETE FROM channels WHERE name='TWILIO-E2E'");
$ch = Db::insert('channels', ['tenant_id' => 1, 'type' => 'twilio_whatsapp', 'name' => 'TWILIO-E2E', 'external_id' => '+14155238886', 'account_sid' => $sid, 'access_token' => Crypto::encrypt($tok), 'verify_token' => 'v', 'channel_key' => $key]);
$url = rtrim((string)env('APP_URL', 'http://localhost/calichat'), '/') . "/webhook/$key";
$send = function (string $id, string $body) use ($url, $sid, $tok) {
    $f = ['MessageSid' => $id, 'AccountSid' => $sid, 'From' => 'whatsapp:+94771234567', 'To' => 'whatsapp:+14155238886', 'Body' => $body, 'ProfileName' => 'Nimal Perera', 'NumMedia' => '0', 'SmsStatus' => 'received'];
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query($f), CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'X-Twilio-Signature: ' . Twilio::signature($url, $f, $tok)]]);
    $t = microtime(true); $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    printf("POST %-7s -> %d in %dms  %s\n", $id, $code, (microtime(true) - $t) * 1000, substr((string)$r, 0, 60));
};
$send('SMe2e01', 'Where are my orders?');
$send('SMe2e02', 'Do you have any jeans in stock? what is the price of the straight fit one?');
echo "channel=$ch\n";
