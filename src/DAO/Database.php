<?php

declare(strict_types=1);

namespace App\DAO;

use App\Services\ConfigService;
use PDO;

class Database
{
    private ?PDO $connection = null;

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $driver = (string) ConfigService::get('database.driver', 'sqlite');

            if ($driver !== 'sqlite') {
                throw new \RuntimeException('Only SQLite is configured for this project.');
            }

            $path = (string) ConfigService::get('database.path', '../var/data/social_poster.sqlite');
            if (!str_starts_with($path, '/')) {
                $path = BASE_DIR . '/' . ltrim($path, './');
            }

            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $this->connection = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->connection->exec('PRAGMA foreign_keys = ON');
        }

        return $this->connection;
    }
}
