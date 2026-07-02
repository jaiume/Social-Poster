<?php

declare(strict_types=1);

namespace App\DAO;

use App\Services\ConfigService;
use App\Support\SqlDialect;
use PDO;

class Database
{
    private ?PDO $connection = null;

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $driver = (string) ConfigService::get('database.driver', 'sqlite');

            if (SqlDialect::isMysql()) {
                $host = (string) ConfigService::get('database.host', 'localhost');
                $port = (int) ConfigService::get('database.port', 3306);
                $name = (string) ConfigService::get('database.name', '');
                $user = (string) ConfigService::get('database.user', '');
                $password = (string) ConfigService::get('database.password', '');
                $charset = (string) ConfigService::get('database.charset', 'utf8mb4');

                if ($name === '' || $user === '') {
                    throw new \RuntimeException('database.name and database.user are required for MySQL/MariaDB.');
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $host,
                    $port,
                    $name,
                    $charset
                );

                $this->connection = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } elseif ($driver === 'sqlite') {
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
            } else {
                throw new \RuntimeException("Unsupported database driver: {$driver}");
            }
        }

        return $this->connection;
    }
}
