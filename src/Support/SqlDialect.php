<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigService;

final class SqlDialect
{
    public static function isMysql(): bool
    {
        $driver = (string) ConfigService::get('database.driver', 'sqlite');

        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public static function now(): string
    {
        return self::isMysql() ? 'NOW()' : "datetime('now')";
    }

    public static function nowMinusMinutes(int $minutes): string
    {
        if (self::isMysql()) {
            return 'DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)';
        }

        return "datetime('now', '-{$minutes} minutes')";
    }

    public static function nowMinusSeconds(int $seconds): string
    {
        if (self::isMysql()) {
            return 'DATE_SUB(NOW(), INTERVAL ' . $seconds . ' SECOND)';
        }

        return "datetime('now', '-{$seconds} seconds')";
    }

    public static function appSettingsUpsertSql(): string
    {
        $now = self::now();
        if (self::isMysql()) {
            return "INSERT INTO app_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, {$now})
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                    updated_at = {$now}";
        }

        return "INSERT INTO app_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, {$now})
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value,
                updated_at = {$now}";
    }

    public static function schemaMigrationsTableSql(): string
    {
        if (self::isMysql()) {
            return 'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(255) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )';
        }

        return "CREATE TABLE IF NOT EXISTS schema_migrations (
            filename TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )";
    }
}
