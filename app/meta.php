<?php
declare(strict_types=1);
/** /meta/connect and /meta/callback — Facebook Login for Messenger + Instagram (owner only). */

use CaliChat\Core\Audit;
use CaliChat\Core\Auth;
use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Services\MetaConnect;

$u = Auth::require('tenant');
Auth::requireOwner();
$tid = (int)Auth::tenantId();
$action = $seg[1] ?? '';

if (!MetaConnect::configured()) { flash('One-click connect is not enabled on this platform yet. Ask your administrator, or connect manually below.', 'err'); redirect('app/channels'); }

if ($action === 'connect') {
    redirect(MetaConnect::loginUrl(MetaConnect::newState($tid, (int)$u['id'])));
}

if ($action === 'callback') {
    if (isset($_GET['error'])) { flash('Facebook sign-in was cancelled or refused: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 160), 'err'); redirect('app/channels'); }
    if (!MetaConnect::checkState((string)($_GET['state'] ?? ''), $tid, (int)$u['id'])) { Audit::log('meta_connect_bad_state'); flash('That sign-in link expired or did not come from this session. Please try Connect again.', 'err'); redirect('app/channels'); }
    try {
        $token = MetaConnect::tokenFromCode((string)($_GET['code'] ?? ''));
        $pages = MetaConnect::pages($token);
    } catch (\Throwable $e) { Log::exception($e, 'meta_connect_failed'); flash($e->getMessage(), 'err'); redirect('app/channels'); }
    if (!$pages) { flash('Facebook did not return any Pages. Make sure you selected at least one Page (and its Instagram account) in the Facebook dialog and that you are an admin of it.', 'err'); redirect('app/channels'); }
    $id = rand_key(16);
    Db::insert('meta_pending', ['id' => $id, 'tenant_id' => $tid, 'user_id' => $u['id'], 'payload' => Crypto::encrypt(json_encode($pages)), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 900)]);
    redirect('app/channels?pick=' . $id);
}
http_response_code(404); echo 'Not found';
