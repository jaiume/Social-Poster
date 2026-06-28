<?php

declare(strict_types=1);

namespace App\Support;

final class NodeProcess
{
    public static function browsersPath(): string
    {
        return BASE_DIR . '/var/playwright-browsers';
    }

    /**
     * @return array<string, string>
     */
    public static function env(): array
    {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_') || $key === 'REQUEST_METHOD') {
                continue;
            }
            $env[$key] = $value;
        }

        $env['PLAYWRIGHT_BROWSERS_PATH'] = self::browsersPath();
        $env['PATH'] = $env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return $env;
    }

    public static function nodeBinary(): string
    {
        foreach (['/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $which !== '' ? $which : 'node';
    }
}
