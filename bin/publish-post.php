#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\Services\PostingOrchestratorService;
use App\Support\PlaywrightLock;

$postId = (int) ($argv[1] ?? 0);
if ($postId <= 0) {
    fwrite(STDERR, "Usage: php bin/publish-post.php <post_id>\n");
    exit(1);
}

$container = require BASE_DIR . '/config/container.php';
/** @var PostingOrchestratorService $orchestrator */
$orchestrator = $container->get(PostingOrchestratorService::class);

$batchId = bin2hex(random_bytes(8));
$context = [
    'publish_batch_id' => $batchId,
];

echo "Publishing post {$postId} (batch {$batchId})\n";

$begin = $orchestrator->beginPublishing($postId, $context);
if (!$begin['success']) {
    fwrite(STDERR, "beginPublishing failed: {$begin['message']}\n");
    exit(1);
}

$context['publish_batch_id'] = (string) ($begin['data']['publish_batch_id'] ?? $batchId);
$orchestrator->setPublishContext($context);

$plan = $begin['data']['steps'] ?? $orchestrator->buildPublishPlan($postId, $context);
if ($plan === []) {
    fwrite(STDERR, "No publish steps.\n");
    exit(1);
}

$failed = false;
foreach ($plan as $step) {
    $label = $step['label'] ?? 'step';
    $sessionAccountId = (int) ($step['session_account_id'] ?? 0);
    $action = (string) $step['action'];
    $platform = (string) $step['platform'];
    echo "\n=== {$label} (account {$sessionAccountId}, {$action}) ===\n";

    PlaywrightLock::acquire();
    try {
        $result = $orchestrator->executePublishStepByAccount($postId, $sessionAccountId, $action, $platform);
    } finally {
        PlaywrightLock::release();
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $stepStatus = (string) ($result['data']['step_status'] ?? 'failed');
    if (!$result['success'] || $stepStatus === 'failed') {
        $failed = true;
        echo "Step failed; stopping.\n";
        break;
    }

    if ($action === 'repost') {
        $delayMs = (int) ($container->get(\App\Services\AppSettingsService::class)->getInt('browser_repost_delay_ms', 45000));
        if ($delayMs > 0) {
            echo "Repost delay {$delayMs}ms...\n";
            usleep($delayMs * 1000);
        }
    }
}

$complete = $orchestrator->completePublishing($postId);
echo "\n=== Complete ===\n";
echo json_encode($complete, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($failed ? 1 : 0);
