#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\Services\BrowserSessionService;
use App\Support\NodeProcess;

$sessionId = (int) ($argv[1] ?? 1);
$pageUrl = (string) ($argv[2] ?? 'https://www.facebook.com/me');

$container = require BASE_DIR . '/config/container.php';
$sessionService = $container->get(BrowserSessionService::class);

$sessionData = $sessionService->writeDecryptedToTemp($sessionId);
if ($sessionData === null) {
    fwrite(STDERR, "No active session for id {$sessionId}.\n");
    exit(1);
}

$screenshotDir = BASE_DIR . '/var/logs/browser';
if (!is_dir($screenshotDir)) {
    mkdir($screenshotDir, 0755, true);
}
$screenshotPath = $screenshotDir . '/debug-fb-identity-' . time() . '.png';

$payload = [
    'sessionPath' => $sessionData['path'],
    'headless' => true,
    'pageUrl' => $pageUrl,
    'screenshotPath' => $screenshotPath,
    'timeoutMs' => 30000,
];

$proc = proc_open(
    [NodeProcess::nodeBinary(), BASE_DIR . '/automation/var/cache/debug-fb-identity.mjs'],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    BASE_DIR . '/automation',
    NodeProcess::env()
);

if (!is_resource($proc)) {
    @unlink($sessionData['path']);
    fwrite(STDERR, "Failed to start debug script.\n");
    exit(1);
}

fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
$sessionService->deleteTemp($sessionData['path']);

fwrite(STDERR, "=== STDERR ===\n" . $stderr . "\n");
echo "=== STDOUT ===\n";
$result = json_decode($stdout ?: '{}', true);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "Screenshot: {$screenshotPath}\n";
