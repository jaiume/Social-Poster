<?php

declare(strict_types=1);

namespace App\Support;

final class PlaywrightLock
{
    /** @var resource|null */
    private static $fp = null;

    public static function acquire(): void
    {
        if (self::$fp !== null) {
            return;
        }

        $path = BASE_DIR . '/var/playwright.lock';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $fp = fopen($path, 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            throw new \RuntimeException('Could not acquire playwright lock.');
        }
        self::$fp = $fp;
    }

    public static function release(): void
    {
        if (!is_resource(self::$fp)) {
            return;
        }
        flock(self::$fp, LOCK_UN);
        fclose(self::$fp);
        self::$fp = null;
    }
}
