<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

final class TestDatabase
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite::memory:');
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::applySchema(self::$pdo);
        }

        return self::$pdo;
    }

    public static function resetProfiles(): void
    {
        $pdo = self::connection();
        $pdo->exec('DELETE FROM product_profiles');
    }

    /** @return array{profile_id: int, session_account_id: int, browser_session_id: int, platform: string} */
    public static function seedPostingAccount(string $platform = 'facebook'): array
    {
        $pdo = self::connection();
        $pdo->exec("INSERT INTO browser_sessions (name, platform, storage_state, status) VALUES ('test-session-" . uniqid('', true) . "', '{$platform}', '{}', 'active')");
        $sessionId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO session_accounts (browser_session_id, account_kind, display_name, is_active) VALUES ({$sessionId}, 'root', 'Test root', 1)");
        $accountId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_profiles (name, slug, is_active) VALUES ('Test', 'test-" . uniqid('', true) . "', 1)");
        $profileId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO profile_posting_accounts (product_profile_id, platform, session_account_id)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$profileId, $platform, $accountId]);

        return [
            'profile_id' => $profileId,
            'session_account_id' => $accountId,
            'browser_session_id' => $sessionId,
            'platform' => $platform,
        ];
    }

    private static function applySchema(PDO $pdo): void
    {
        $path = BASE_DIR . '/docs/db/current_schema.sql';
        $sql = (string) file_get_contents($path);
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
