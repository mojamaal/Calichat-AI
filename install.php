<?php
declare(strict_types=1);
/** CLI installer / upgrader:  php install.php   (safe to re-run: applies pending migrations, never overwrites data) */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line: php install.php'); }

$envFile = __DIR__ . '/.env';
if (!is_file($envFile)) {
    $tpl = file_get_contents(__DIR__ . '/.env.example');
    $tpl = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(32)), $tpl);
    $tpl = preg_replace('/^CRON_KEY=.*$/m', 'CRON_KEY=' . bin2hex(random_bytes(16)), $tpl);
    file_put_contents($envFile, $tpl);
    echo "Created .env (add GEMINI_API_KEY to it)\n";
}
require __DIR__ . '/config/bootstrap.php';

use CaliChat\Services\DemoData;
use CaliChat\Core\Db;
use CaliChat\Core\Migrator;
use CaliChat\Services\Knowledge;

$pdo = new PDO('mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';charset=utf8mb4', (string)env('DB_USER', 'root'), (string)env('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = preg_replace('/[^A-Za-z0-9_]/', '', (string)env('DB_NAME', 'calichat_ai'));
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");
$n = Migrator::run($pdo, fn($m) => print("  $m\n"));
echo $n ? "Applied $n migration(s) to `$db`\n" : "Database `$db` is up to date\n";

if (!Db::val("SELECT id FROM users WHERE role='super_admin' LIMIT 1")) {
    $pw = bin2hex(random_bytes(9)) . 'Aa1!';
    Db::insert('users', ['name' => 'Super Admin', 'email' => 'admin@calichat.local', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'super_admin']);
    echo "\nSUPER ADMIN  admin@calichat.local  /  $pw\n";
}
if (!Db::val("SELECT id FROM tenants LIMIT 1")) {
    $pw = bin2hex(random_bytes(9)) . 'Aa1!';
    $tid = Db::insert('tenants', ['name' => 'Demo Store', 'slug' => 'demo-store', 'plan' => 'starter', 'monthly_message_limit' => 2000, 'settings' => '{}']);
    Db::insert('users', ['tenant_id' => $tid, 'name' => 'Demo Owner', 'email' => 'demo@calichat.local', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'owner']);
    Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Kavi', 'business_name' => 'Demo Store', 'tone' => 'friendly', 'temperature' => 1.0,
        'instructions' => "We are an online fashion store in Sri Lanka. Prices are in LKR. Delivery island-wide in 2-4 working days; Colombo 1-2 days. Cash on delivery available.\nWhen asked about products or stock use search_products. For order status ask for the order number and use get_order. Suggest alternatives when something is out of stock.",
        'fallback_message' => 'Sorry, we are having a small issue. A team member will reply shortly.']);
    DemoData::ensureTools($tid); // demo connector + 4 tools wired to the demo ERP (24 products, 12 orders in MySQL)
    $doc = Db::insert('knowledge_docs', ['tenant_id' => $tid, 'title' => 'Store policies', 'content' => "Returns: Items can be returned within 7 days of delivery if unused with tags. Refunds go to the original payment method in 5-7 working days.\n\nShipping: Island-wide delivery in 2-4 working days. Colombo suburbs 1-2 days. Delivery fee Rs. 350, free over Rs. 10,000.\n\nPayment: Cash on delivery, bank transfer and card payments are accepted.\n\nOpening hours: Our team is available 9am-6pm Monday to Saturday."]);
    Knowledge::index($tid, $doc, (string)Db::val("SELECT content FROM knowledge_docs WHERE id=?", [$doc]));
    echo "\nTip: php cli/seed_demo.php <your WhatsApp number> creates sample orders for the phone you will test from.\n";
    echo "\nDEMO TENANT  demo@calichat.local  /  $pw\n";
}
echo "\nNext: start the queue worker (start-worker.bat / php cli/worker.php), then open " . abs_url('') . "\n";
