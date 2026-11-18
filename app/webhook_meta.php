<?php
declare(strict_types=1);
/**
 * /webhook/meta — the ONE webhook of the platform's Meta app (WhatsApp, Messenger, Instagram for every connected tenant).
 * Signed with the platform app secret; each entry is routed to the tenant's managed channel by page / account / phone number id.
 */

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Services\Meta;
use CaliChat\Services\MetaConnect;
use CaliChat\Services\Queue;

if ($_SERVER['REQUEST_METHOD'] === 'GET') { // Meta's verification handshake
    if (MetaConnect::configured() && ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && hash_equals(MetaConnect::verifyToken(), (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) {
        header('Content-Type: text/plain'); echo $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''; exit;
    }
    http_response_code(403); exit('forbidden');
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 1048576) { http_response_code(413); exit('too large'); }
if (!MetaConnect::configured() || !Meta::verifySignature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null, MetaConnect::appSecret())) {
    Log::warn('webhook_bad_signature', ['type' => 'meta_platform']);
    http_response_code(401); exit('bad signature');
}

$payload = json_decode($raw, true);
$stored = 0;
if (is_array($payload)) { try { \CaliChat\Services\LeadAds::handle($payload); } catch (\Throwable $e) { Log::exception($e, 'leadgen_webhook_failed'); } }
foreach (is_array($payload) ? Meta::route($payload) : [] as [$channel, $events]) {
    if (Db::val("SELECT status FROM tenants WHERE id=?", [$channel['tenant_id']]) !== 'active') continue;
    foreach ($events as $ev) {
        try { if (Queue::enqueue($channel, $ev)) $stored++; }
        catch (\Throwable $e) { Log::exception($e, 'enqueue_failed', ['channel' => (int)$channel['id']]); http_response_code(500); exit('retry'); }
    }
    Db::update('channels', ['last_webhook_at' => now()], 'id=?', [$channel['id']]);
}

ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain'); header('Connection: close'); header('Content-Length: 2');
echo 'ok';
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { while (ob_get_level() > 0) @ob_end_flush(); flush(); }
if ($stored > 0 && env('QUEUE_INLINE_DRAIN', '1') === '1' && !Queue::workerAlive()) {
    set_time_limit(60);
    try { Queue::drain(3, 40); } catch (\Throwable $e) { Log::exception($e, 'inline_drain_failed'); }
}
