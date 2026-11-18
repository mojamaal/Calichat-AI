<?php
declare(strict_types=1);
/** Lists the tools your TencentDB Agent Memory deployment actually exposes, so TENCENT_TOOL_* can be set correctly. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Memory\TencentMemory;

echo "GET " . env('TENCENT_MEMORY_URL') . "/v3/tools/list\n";
$r = TencentMemory::listTools();
echo "status: {$r['status']}" . ($r['error'] ? "  error: {$r['error']}" : '') . "\n";
$j = json_decode($r['body'], true);
echo $j ? json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $r['body'], "\n";
echo "\nConfigured: TENCENT_TOOL_RECALL=" . env('TENCENT_TOOL_RECALL', 'memory_recall') . "  TENCENT_TOOL_CAPTURE=" . env('TENCENT_TOOL_CAPTURE', 'memory_capture') . "\n";
