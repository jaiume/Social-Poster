<?php

declare(strict_types=1);

namespace App\Support;

final class PlaybookFormat
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param array<string, mixed>|null $playbook
     */
    public static function isV2(?array $playbook): bool
    {
        if ($playbook === null || $playbook === []) {
            return false;
        }

        return (int) ($playbook['schema_version'] ?? 0) === self::SCHEMA_VERSION;
    }

    public static function decodeJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '' || trim($json) === '{}') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $playbook
     */
    public static function usesLegacyLocators(?array $playbook): bool
    {
        if ($playbook === null) {
            return false;
        }

        $json = json_encode($playbook);
        if ($json === false) {
            return false;
        }

        return str_contains($json, '"kind"');
    }
}
