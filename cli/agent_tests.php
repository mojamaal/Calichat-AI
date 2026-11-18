<?php
declare(strict_types=1);
/**
 * Run a business's conversation tests from the command line (real AI, uses tokens):
 *   php cli/agent_tests.php <tenant_id>        run every test of one tenant
 *   php cli/agent_tests.php <tenant_id> <name> run the tests whose name contains <name>
 * Exit code 1 when any test fails, so it can gate a prompt change in a script or CI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Db;
use CaliChat\Services\AgentTests;

$tid = (int)($argv[1] ?? 0); $filter = $argv[2] ?? '';
if (!$tid || !Db::val("SELECT id FROM tenants WHERE id=?", [$tid])) { fwrite(STDERR, "Usage: php cli/agent_tests.php <tenant_id> [name filter]\n"); exit(2); }
$tests = Db::all("SELECT id, name FROM agent_tests WHERE tenant_id=? AND name LIKE ? ORDER BY id", [$tid, '%' . $filter . '%']);
if (!$tests) { echo "No tests found.\n"; exit(0); }
$fail = 0;
foreach ($tests as $t) {
    $r = AgentTests::run($tid, (int)$t['id']);
    echo ($r['passed'] ? 'PASS' : 'FAIL') . '  ' . $t['name'] . '  (' . round($r['ms'] / 1000, 1) . " s)\n";
    if ($r['error']) echo "      {$r['error']}\n";
    if (!$r['passed']) { $fail++; foreach ($r['turns'] as $turn) foreach ($turn['checks'] as $c) if (!$c['ok']) echo "      ✕ {$c['name']}" . ($c['detail'] ? " ({$c['detail']})" : '') . "\n"; }
}
echo "\n" . (count($tests) - $fail) . ' passed, ' . $fail . " failed\n";
exit($fail ? 1 : 0);
