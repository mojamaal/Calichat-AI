<?php
declare(strict_types=1);
/**
 * Demo history for one business: customers, chats, orders and revenue, leads, hand-offs and daily usage.
 *   php cli/seed_sales.php [tenant_id]            add it (tenant 1 by default); refuses if it is already there
 *   php cli/seed_sales.php [tenant_id] --clear    remove only what this script added
 *   php cli/seed_sales.php [tenant_id] --reset    remove, then add a fresh set
 * Everything added is tagged "demo data". Stock is never changed and nothing is sent to anyone.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Tenant;
use CaliChat\Services\DemoSales;

$args = array_slice($argv, 1); $tid = 1; foreach ($args as $a) if (ctype_digit($a)) $tid = (int)$a;
$t = Tenant::get($tid); if (!$t) { fwrite(STDERR, "No tenant $tid\n"); exit(1); }
set_tz((string)$t['timezone']);
try {
    if (in_array('--clear', $args, true) || in_array('--reset', $args, true)) { echo "Removed: " . json_encode(DemoSales::clear($tid)) . "\n"; if (in_array('--clear', $args, true)) exit(0); }
    $r = DemoSales::seed($tid); echo "Added for '{$t['name']}': " . json_encode($r) . "\n";
} catch (\Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
