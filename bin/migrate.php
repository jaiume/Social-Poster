#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\Database;

$pdo = (new Database())->getConnection();
$migrationsDir = BASE_DIR . '/docs/db/migrations';

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)');

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob($migrationsDir . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }

    echo "Applying {$filename}...\n";
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
        $pdo->commit();
        $ran++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Failed: {$e->getMessage()}\n");
        exit(1);
    }
}

echo $ran > 0 ? "Applied {$ran} migration(s).\n" : "No pending migrations.\n";
