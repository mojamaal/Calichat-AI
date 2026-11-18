<?php
declare(strict_types=1);
/** One-shot tick for cron / Task Scheduler (every minute):  php cron/worker.php   (HTTP: ?key=CRON_KEY). Campaigns + health + cleanup; the queue worker is cli/worker.php. */
require dirname(__DIR__) . '/config/bootstrap.php';

use CaliChat\Services\Jobs;
use CaliChat\Services\Queue;

if (PHP_SAPI !== 'cli') {
    $k = (string)env('CRON_KEY', '');
    if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
}
set_time_limit(110);
Queue::recoverStale();
$sent = Jobs::campaignTick();
Jobs::healthChecks();
$resumed = Jobs::autoResume(); Jobs::catchUp();
$auto = Jobs::automationTick();
$scan = (int)date('i') % 5 === 0 ? Jobs::automationScan() : [];
$bill = (int)date('i') === 0 ? Jobs::billingTick() : [];
$m = (int)date('i') % 10 === 0 ? Jobs::maintenance() : [];
echo "campaign recipients sent: $sent; auto-resumed: $resumed; automations processed: $auto" . ($scan ? '; scan: ' . json_encode($scan) : '') . ($m ? '; maintenance: ' . json_encode($m) : '') . "\n";
