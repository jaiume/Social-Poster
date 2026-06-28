<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

final class TimezoneHelper
{
    public static function normalize(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return 'UTC';
        }

        return str_replace('-', '_', $timezone);
    }

    public static function isValid(string $timezone): bool
    {
        $timezone = self::normalize($timezone);

        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public static function resolve(string $timezone): DateTimeZone
    {
        $normalized = self::normalize($timezone);
        if (!self::isValid($normalized)) {
            throw new \InvalidArgumentException('Invalid timezone: ' . $timezone);
        }

        return new DateTimeZone($normalized);
    }
}
