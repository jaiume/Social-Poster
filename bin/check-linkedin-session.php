#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\Services\BrowserSessionService;
use App\Support\NodeProcess;

$sessionId = (int) ($argv[1] ?? 3);
$extraUrl = isset($argv[2]) && $argv[2] !== '' ? (string) $argv[2] : null;

$container = require BASE_DIR . '/config/container.php';
$sessionService = $container->get(BrowserSessionService::class);

$sessionData = $sessionService->writeDecryptedToTemp($sessionId);
if ($sessionData === null) {
    fwrite(STDERR, "No active session for id {$sessionId}.\n");
    exit(1);
}

$urls = ['https://www.linkedin.com/feed/'];
if ($extraUrl !== null) {
    $urls[] = $extraUrl;
}

$payload = [
    'sessionPath' => $sessionData['path'],
    'headless' => true,
    'screenshotDir' => BASE_DIR . '/var/logs/browser',
    'urls' => $urls,
];

$payloadPath = sys_get_temp_dir() . '/social_poster_li_check_' . getmypid() . '.json';
file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

$proc = proc_open(
    [NodeProcess::nodeBinary(), BASE_DIR . '/automation/check-linkedin-session.js', $payloadPath],
    [
        0 => ['pipe', 'w'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    BASE_DIR . '/automation',
    NodeProcess::env()
);

if (!is_resource($proc)) {
    @unlink($payloadPath);
    $sessionService->deleteTemp($sessionData['path']);
    fwrite(STDERR, "Failed to start check script.\n");
    exit(1);
}

fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
@unlink($payloadPath);
$sessionService->deleteTemp($sessionData['path']);

if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}

$result = json_decode($stdout ?: '{}', true);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (!is_array($result) || empty($result['success'])) {
    exit(1);
}

if (!empty($result['captchaDetected'])) {
    exit(2);
}

if (empty($result['sessionHealthy'])) {
    exit(3);
}
