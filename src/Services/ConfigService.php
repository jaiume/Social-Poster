<?php

declare(strict_types=1);

namespace App\Services;

class ConfigService
{
    private static ?array $config = null;

    public static function configPath(): string
    {
        $override = getenv('SOCIAL_POSTER_CONFIG');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return BASE_DIR . '/config/config.ini';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $path = self::configPath();
            if (!is_readable($path)) {
                throw new \RuntimeException(
                    'Missing config/config.ini. Copy config/config.ini.example and configure it.'
                );
            }

            self::$config = parse_ini_file($path, true, INI_SCANNER_TYPED);
        }

        if (str_contains($key, '.')) {
            [$section, $name] = explode('.', $key, 2);

            return self::$config[$section][$name] ?? $default;
        }

        foreach (self::$config as $section) {
            if (is_array($section) && array_key_exists($key, $section)) {
                return $section[$key];
            }
        }

        return $default;
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
