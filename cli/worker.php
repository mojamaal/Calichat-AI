<?php
declare(strict_types=1);
/**
 * Queue worker daemon:  php cli/worker.php
 * Run one or more (they coordinate through the database). Stop with: create storage/worker.stop
 * Windows: start-worker.bat keeps it running. Linux: use systemd/supervisor.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Core\Db;
use CaliChat\Core\Log;
use CaliChat\Services\Jobs;
use CaliChat\Services\Queue;

set_time_limit(0);
$id = 'w' . getmypid();
$start = time(); $lastStale = $lastCampaign = $lastHealth = $lastMaint = $lastAuto = $lastScan = $lastBill = 0; $done = 0;
$stop = ROOT . '/storage/worker.stop';
if (is_file($stop)) @unlink($stop);
echo "[$id] worker started\n";
Log::info('worker_started', ['worker' => $id]);

while (true) {
    if (is_file($stop)) { echo "[$id] stop file found, exiting\n"; break; }
    if (time() - $start > 3600) { echo "[$id] hourly restart\n"; break; } // supervisors restart us: frees memory
    try {
        Queue::beat('queue', "$id processed=$done");
        $t = time();
        if ($t - $lastStale >= 30) { $n = Queue::recoverStale(); if ($n) Log::warn('stale_events_recovered', ['n' => $n]); $lastStale = $t; }
        $ev = Queue::claim($id);
        if ($ev) { Queue::runOne($ev); $done++; continue; }
        Jobs::catchUp(); // chats a person just handed back to the AI with an unanswered customer message
        if ($t - $lastCampaign >= 20) { Jobs::campaignTick(); $lastCampaign = $t; }
        if ($t - $lastAuto >= 30) { Jobs::automationTick(); $lastAuto = $t; }
        if ($t - $lastScan >= 300) { Jobs::automationScan(); $lastScan = $t; }
        if ($t - $lastBill >= 3600) { Jobs::billingTick(); $lastBill = $t; }
        if ($t - $lastHealth >= 60) { Jobs::healthChecks(); Jobs::autoResume(); $lastHealth = $t; }
        if ($t - $lastMaint >= 600) { Jobs::maintenance(); $lastMaint = $t; }
        usleep(700000);
    } catch (\Throwable $e) {
        Log::exception($e, 'worker_loop_error');
        echo "[$id] error: " . $e->getMessage() . "\n";
        Db::reset(); sleep(3); // reconnect after "server has gone away" etc.
    }
}
