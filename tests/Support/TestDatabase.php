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
