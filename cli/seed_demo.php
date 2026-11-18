<?php
declare(strict_types=1);
/**
 * Demo store helper:   php cli/seed_demo.php [your WhatsApp number] ["Your name"]
 * - Refreshes the demo tenant's tools (search_products, get_product, get_order, list_my_orders).
 * - Creates three sample orders (shipped, delivered, processing) for the number you will test from,
 *   so "Where is my order?" returns YOUR orders. Number format: country code, digits only, e.g. 94771234567.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Db;
use CaliChat\Services\DemoData;

$t = Db::one("SELECT id FROM tenants WHERE slug='demo-store' LIMIT 1") ?: Db::one("SELECT id FROM tenants ORDER BY id LIMIT 1");
if (!$t) exit("No tenant found. Run php install.php first.\n");
DemoData::ensureTools((int)$t['id']);
echo "Demo tools refreshed for tenant #{$t['id']}.\n";
if (!empty($argv[1])) {
    $orders = DemoData::seedPhone($argv[1], $argv[2] ?? 'Test Customer');
    echo 'Orders for ' . DemoData::digits($argv[1]) . ': ' . implode(', ', $orders) . "\n";
} else {
    echo "Tip: php cli/seed_demo.php 94771234567 \"Your Name\"  (creates sample orders for your own phone number)\n";
}
echo 'Products: ' . Db::val("SELECT COUNT(*) FROM demo_products") . ', orders: ' . Db::val("SELECT COUNT(*) FROM demo_orders") . "\n";
