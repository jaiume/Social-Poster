#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\Database;

$days = max(1, (int) ($argv[1] ?? 7));
$since = gmdate('c', time() - ($days * 86400));

$container = require BASE_DIR . '/config/container.php';
$db = $container->get(Database::class)->getConnection();

echo "Posting hardening metrics for last {$days} day(s) since {$since}\n\n";

$attemptTableExists = (bool) $db->query(
    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='publication_attempt_states'"
)->fetchColumn();

$totals = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
     FROM post_publications
     WHERE created_at >= ?"
);
$totals->execute([$since]);
$summary = $totals->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'success' => 0, 'failed' => 0];

echo "Post publications:\n";
echo "- total: " . (int) $summary['total'] . "\n";
echo "- success: " . (int) $summary['success'] . "\n";
echo "- failed: " . (int) $summary['failed'] . "\n\n";

if (!$attemptTableExists) {
    echo "publication_attempt_states table not found; apply migration first for full metrics.\n";
    exit(0);
}

$resolver = $db->prepare(
    "SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN resolver_confidence = 'none' THEN 1 ELSE 0 END) AS unresolved,
        SUM(CASE WHEN verification_confidence = 'weak' THEN 1 ELSE 0 END) AS weak_verify,
        SUM(CASE WHEN error_code = 'VERIFY_FAILED' THEN 1 ELSE 0 END) AS verify_failed
     FROM publication_attempt_states
     WHERE started_at >= ?"
);
$resolver->execute([$since]);
$resolverRow = $resolver->fetch(PDO::FETCH_ASSOC) ?: [
    'total_attempts' => 0,
    'unresolved' => 0,
    'weak_verify' => 0,
    'verify_failed' => 0,
];

$totalAttempts = max(1, (int) $resolverRow['total_attempts']);
$unresolvedPct = ((int) $resolverRow['unresolved'] / $totalAttempts) * 100;

echo "Attempt-state metrics:\n";
echo "- total attempts: " . (int) $resolverRow['total_attempts'] . "\n";
echo "- unresolved (resolverConfidence=none): " . (int) $resolverRow['unresolved']
    . sprintf(" (%.2f%%)\n", $unresolvedPct);
echo "- weak verification: " . (int) $resolverRow['weak_verify'] . "\n";
echo "- verify failed: " . (int) $resolverRow['verify_failed'] . "\n\n";

$runtime = $db->prepare(
    "SELECT
        platform,
        action,
        CAST((julianday(ended_at) - julianday(started_at)) * 86400 AS INTEGER) AS runtime_seconds
     FROM publication_attempt_states
     WHERE started_at >= ?
       AND ended_at IS NOT NULL
       AND started_at IS NOT NULL"
);
$runtime->execute([$since]);
$rows = $runtime->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    echo "No runtime rows in publication_attempt_states for selected window.\n";
    exit(0);
}

$groups = [];
foreach ($rows as $row) {
    if ((int) $row['runtime_seconds'] < 0) {
        continue;
    }
    $key = $row['platform'] . ':' . $row['action'];
    $groups[$key][] = (int) $row['runtime_seconds'];
}

if ($groups === []) {
    echo "No valid runtime rows in publication_attempt_states for selected window.\n";
    exit(0);
}

echo "Runtime (p95) by platform/action:\n";
foreach ($groups as $key => $values) {
    sort($values);
    $idx = (int) floor(0.95 * (count($values) - 1));
    $p95 = $values[$idx];
    echo "- {$key}: {$p95}s (n=" . count($values) . ")\n";
}
