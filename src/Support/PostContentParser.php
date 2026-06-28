<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ImageGenerationService;

class PostContentParser
{
    /**
     * @return array{content: string, image_prompt: ?string}|null
     */
    public static function parse(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $content = $matches[0];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['content'])) {
            $parsed = self::parseContentField($data['content'], $data);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $fromPlatforms = self::parsePlatformBlocks($data);
        if ($fromPlatforms !== null) {
            return $fromPlatforms;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{content: string, image_prompt: ?string}|null
     */
    private static function parseContentField(mixed $content, array $data): ?array
    {
        if (is_string($content) && trim($content) !== '') {
            return [
                'content' => ImageGenerationService::stripVisualConceptFromContent(trim($content)),
                'image_prompt' => self::stringOrNull($data['image_prompt'] ?? null),
            ];
        }

        if (!is_array($content)) {
            return null;
        }

        if (isset($content['content']) && is_string($content['content']) && trim($content['content']) !== '') {
            return [
                'content' => ImageGenerationService::stripVisualConceptFromContent(trim($content['content'])),
                'image_prompt' => self::stringOrNull($content['image_prompt'] ?? $data['image_prompt'] ?? null),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{content: string, image_prompt: ?string}|null
     */
    private static function parsePlatformBlocks(array $data): ?array
    {
        $text = '';
        $imagePrompt = null;

        foreach (['facebook', 'linkedin'] as $platform) {
            if (!array_key_exists($platform, $data)) {
                continue;
            }

            $block = $data[$platform];
            if (is_string($block)) {
                $candidate = trim($block);
            } elseif (is_array($block)) {
                $candidate = trim((string) ($block['content'] ?? ''));
                if ($imagePrompt === null) {
                    $imagePrompt = self::stringOrNull($block['image_prompt'] ?? null);
                }
            } else {
                continue;
            }

            if ($candidate === '') {
                continue;
            }

            if ($text === '') {
                $text = $candidate;
            } elseif ($text !== $candidate) {
                // Unified post: keep the first platform block when copy differs.
                break;
            }
        }

        if ($text === '') {
            return null;
        }

        if ($imagePrompt === null) {
            $imagePrompt = self::stringOrNull($data['image_prompt'] ?? null);
        }

        return [
            'content' => ImageGenerationService::stripVisualConceptFromContent($text),
            'image_prompt' => $imagePrompt,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
