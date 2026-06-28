#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\Database;
use App\Services\BrowserSessionService;
use App\DAO\BrowserSessionDao;
use App\Services\EncryptionService;

if ($argc < 3) {
    fwrite(STDERR, "Usage: bin/import-session.php <session-name-or-id> <path-to-storageState.json>\n");
    exit(1);
}

$sessionRef = $argv[1];
$path = $argv[2];

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read file: {$path}\n");
    exit(1);
}

$json = file_get_contents($path);
$db = new Database();
$encryption = new EncryptionService();
$dao = new BrowserSessionDao($db->getConnection());
$service = new BrowserSessionService($dao, $encryption);

$sessionId = ctype_digit($sessionRef)
    ? (int) $sessionRef
    : (int) (($dao->findByName($sessionRef)['id'] ?? 0));

if ($sessionId <= 0) {
    fwrite(STDERR, "Session not found: {$sessionRef}\n");
    exit(1);
}

$result = $service->importSession($sessionId, $json);

if (!$result['success']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo "Session imported for id {$sessionId}.\n";
