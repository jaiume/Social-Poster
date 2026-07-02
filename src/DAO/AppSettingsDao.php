<?php

declare(strict_types=1);

namespace App\DAO;

use App\Support\SqlDialect;

class AppSettingsDao extends BaseDao
{
    public function getAll(): array
    {
        $rows = $this->db->query('SELECT * FROM app_settings ORDER BY setting_key')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row;
        }

        return $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare(SqlDialect::appSettingsUpsertSql());
        $stmt->execute([$key, $value]);
    }

    public function isSecret(string $key): bool
    {
        $stmt = $this->db->prepare('SELECT is_secret FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);

        return (bool) $stmt->fetchColumn();
    }
}
