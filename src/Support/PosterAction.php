<?php

declare(strict_types=1);

namespace App\Support;

final class PosterAction
{
    public const FACEBOOK_POST = 'facebook.post';
    public const FACEBOOK_RESOLVE_PRIMARY = 'facebook.resolvePrimary';
    public const LINKEDIN_POST = 'linkedin.post';
    public const FACEBOOK_REPOST = 'facebook.repost';
    public const LINKEDIN_REPOST = 'linkedin.repost';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FACEBOOK_POST,
            self::FACEBOOK_RESOLVE_PRIMARY,
            self::LINKEDIN_POST,
            self::FACEBOOK_REPOST,
            self::LINKEDIN_REPOST,
        ];
    }

    public static function platform(string $action): string
    {
        $parts = explode('.', $action, 2);

        return $parts[0] ?? '';
    }
}
