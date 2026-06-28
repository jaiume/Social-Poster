<?php

declare(strict_types=1);

namespace App\Support;

final class ImageBytesValidator
{
    /** Grok/Flux marketing images are typically 100KB+; blank JPEGs are often under 30KB. */
    private const MIN_BYTES = 30000;

    public static function isLikelyBlank(string $bytes): bool
    {
        if ($bytes === '') {
            return true;
        }

        if (strlen($bytes) < self::MIN_BYTES) {
            return true;
        }

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                return true;
            }

            $width = imagesx($img);
            $height = imagesy($img);
            $sum = 0.0;
            $samples = 0;
            $stepX = max(1, (int) ($width / 12));
            $stepY = max(1, (int) ($height / 12));
            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    $rgb = imagecolorat($img, $x, $y);
                    $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
                    $samples += 3;
                }
            }
            imagedestroy($img);

            if ($samples > 0 && ($sum / $samples) < 12.0) {
                return true;
            }
        }

        return false;
    }

    public static function extensionFor(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
            return 'webp';
        }

        return 'png';
    }

    public static function mimeTypeForExtension(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
