<?php
declare(strict_types=1);
/**
 * Re-encrypt every stored secret with the CURRENT key.
 *   1) add a new key first in .env:  APP_KEYS=k2:<new 64-hex>,k1:<old key>
 *   2) php cli/rotate_keys.php
 *   3) when it reports 0 remaining, the old key can be removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Crypto;
use CaliChat\Core\Db;
use CaliChat\Core\Tenant;

$n = 0; $bad = 0;
$cols = [['channels', 'access_token'], ['channels', 'app_secret'], ['connectors', 'auth_secret']];
foreach ($cols as [$tbl, $col]) {
    foreach (Db::all("SELECT id, `$col` v FROM `$tbl` WHERE `$col` IS NOT NULL") as $r) {
        if (!Crypto::needsRotation($r['v'])) continue;
        $plain = Crypto::decrypt($r['v']);
        if ($plain === null) { $bad++; echo "cannot decrypt $tbl#{$r['id']}.$col (key missing?)\n"; continue; }
        Db::update($tbl, [$col => Crypto::encrypt($plain)], 'id=?', [$r['id']]); $n++;
    }
}
foreach (Db::all("SELECT id FROM tenants") as $t) {
    $tn = Tenant::get((int)$t['id']);
    $blob = $tn['cfg']['gemini_key'] ?? null;
    if ($blob && Crypto::needsRotation($blob)) {
        $plain = Crypto::decrypt($blob);
        if ($plain === null) { $bad++; echo "cannot decrypt tenant#{$t['id']} gemini key\n"; continue; }
        Tenant::saveSettings((int)$t['id'], ['gemini_key' => Crypto::encrypt($plain)]); $n++;
    }
}
echo "re-encrypted $n secret(s) with key '" . Crypto::currentKid() . "'; $bad could not be rotated\n";
