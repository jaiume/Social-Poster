#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\Services\ConfigService;
use App\Services\Task\TaskWorkerService;

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "Usage: php bin/task-worker.php {jobId}\n");
    exit(1);
}

try {
    ConfigService::get('app.name');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

set_time_limit(0);

$container = require BASE_DIR . '/config/container.php';
$worker = $container->get(TaskWorkerService::class);

$result = $worker->run($jobId);

fwrite(STDOUT, '[' . date('c') . "] Job {$jobId} finished.\n");
exit($result['exit_code']);
