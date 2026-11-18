<?php
declare(strict_types=1);
/** Build semantic (embedding) vectors for any knowledge passages that lack one:  php cli/reindex.php [tenant_id] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Db;
use CaliChat\Services\Knowledge;

$only = isset($argv[1]) ? (int)$argv[1] : 0;
foreach (Db::all("SELECT id, name FROM tenants" . ($only ? " WHERE id=" . $only : '')) as $t) {
    $n = Knowledge::reindex((int)$t['id']);
    echo "tenant #{$t['id']} {$t['name']}: embedded $n passage(s)\n";
}
