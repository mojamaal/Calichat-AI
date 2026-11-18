<?php
/**
 * MANUAL live test (real GEMINI_API_KEY, Apache running, dev DB migrated). Creates a throw-away tenant with the built-in store, adds the six starter
 * quality tests and runs them against the real agent. Shows what a business sees when it presses "Run all".   php tests/e2e/quality_e2e.php
 */
require dirname(__DIR__, 2) . '/config/bootstrap.php';
use CaliChat\Core\Db;
use CaliChat\Services\{AgentTests, Store};

$tid = Db::insert('tenants', ['name' => 'Quality E2E', 'slug' => 'quality-e2e-' . rand_key(3), 'settings' => '{}']);
Db::insert('users', ['tenant_id' => $tid, 'name' => 'E2E Owner', 'email' => 'e2e-' . rand_key(3) . '@test.local', 'password_hash' => 'x', 'role' => 'owner']);
Db::insert('agents', ['tenant_id' => $tid, 'name' => 'Kavi', 'business_name' => 'Quality E2E', 'temperature' => 1.0, 'tone' => 'friendly',
    'instructions' => "We are an online fashion store in Sri Lanka. Prices are in LKR. Use search_products for products and prices. Refund and complaint requests must be handed to a person."]);
Store::seedSample($tid); Store::connect($tid); AgentTests::installStarters($tid);
$fail = 0;
foreach (Db::all("SELECT id, name FROM agent_tests WHERE tenant_id=? ORDER BY id", [$tid]) as $t) {
    $r = AgentTests::run($tid, (int)$t['id']);
    echo ($r['passed'] ? 'PASS' : 'FAIL') . '  ' . $t['name'] . '  (' . round($r['ms'] / 1000, 1) . " s)\n";
    foreach ($r['turns'] as $turn) {
        echo '      customer: ' . mb_substr($turn['customer'], 0, 90) . "\n      AI [" . implode(',', $turn['tools']) . ']: ' . str_replace("\n", ' ', mb_substr($turn['reply'], 0, 160)) . "\n";
        foreach ($turn['checks'] as $c) if (!$c['ok']) echo "      ✕ {$c['name']}" . ($c['detail'] ? " ({$c['detail']})" : '') . "\n";
    }
    if (!$r['passed']) $fail++;
}
echo "\n" . (6 - $fail) . " passed, $fail failed\n";
if (getenv('KEEP')) echo "(kept tenant $tid)\n"; else Db::q("DELETE FROM tenants WHERE id=?", [$tid]);
