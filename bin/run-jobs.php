<?php

declare(strict_types=1);

/*
 * Scheduled jobs. Run every 5 minutes from cron (docs/deployment-guide.md):
 *   php bin/run-jobs.php
 * Every job is safe to run twice; a lock file stops two runs overlapping.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

use App\Services\TaskFlowService;
use App\Services\TaskService;
use App\Services\WorkerService;

$lock = fopen(STORAGE_PATH . '/cache/run-jobs.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Another run is in progress.' . PHP_EOL;
    exit(0);
}

$expired = TaskService::expireDue();              // SYS-01
$completed = TaskFlowService::autoComplete();     // SYS-02
$offline = WorkerService::offlineIdle();          // SYS-03
echo date('Y-m-d H:i:s') . " expired=$expired auto_completed=$completed set_offline=$offline" . PHP_EOL;

flock($lock, LOCK_UN);
