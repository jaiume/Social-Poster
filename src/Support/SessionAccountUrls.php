<?php

declare(strict_types=1);

namespace App\Support;

final class SessionAccountUrls
{
    /** @var list<string> */
    private const FACEBOOK_RESERVED_SLUGS = [
        'www', 'login', 'home', 'groups', 'events', 'watch', 'marketplace',
        'profile.php', 'pages', 'gaming', 'reels', 'stories',
    ];

    public static function bootstrapUrl(string $platform, string $accountKind, ?string $subPageId = null): string
    {
        if ($accountKind === 'sub') {
            $locator = trim((string) $subPageId);
            if ($locator === '') {
                throw new \InvalidArgumentException('sub_page_id is required for sub accounts.');
            }

            return match ($platform) {
                'facebook' => self::facebookBootstrapFromLocator($locator),
                'linkedin' => self::linkedinBootstrapFromLocator($locator),
                default => throw new \InvalidArgumentException("Unsupported platform: {$platform}"),
            };
        }

        return match ($platform) {
            'facebook' => 'https://www.facebook.com/',
            'linkedin' => 'https://www.linkedin.com/feed/',
            default => throw new \InvalidArgumentException("Unsupported platform: {$platform}"),
        };
    }

    public static function personalContextUrl(string $platform): string
    {
        return self::bootstrapUrl($platform, 'root');
    }

    public static function primaryPageBrandFromDisplayName(string $platform, string $displayName): ?string
    {
        $suffix = $platform === 'linkedin' ? 'Linkedin' : 'Facebook';
        $brand = preg_replace('/\s+' . preg_quote($suffix, '/') . '$/i', '', trim($displayName));

        return $brand !== '' ? $brand : null;
    }

    public static function memberNameFromDisplayName(string $platform, string $displayName): ?string
    {
        $suffix = $platform === 'linkedin' ? 'LinkedIn' : 'Facebook';
        $name = preg_replace('/\s+' . preg_quote($suffix, '/') . '$/i', '', trim($displayName));

        return $name !== '' ? $name : null;
    }

    /**
     * Normalize user input (id, username, or pasted URL) to a canonical stored locator.
     */
    public static function normalizeSubPageLocator(string $platform, string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Page ID or username is required.');
        }

        return match ($platform) {
            'facebook' => self::normalizeFacebookSubPageLocator($input),
            'linkedin' => self::normalizeLinkedInSubPageLocator($input),
            default => throw new \InvalidArgumentException("Unsupported platform: {$platform}"),
        };
    }

    public static function facebookBootstrapFromLocator(string $locator): string
    {
        $locator = trim($locator);
        if (preg_match('/^\d+$/', $locator)) {
            return 'https://www.facebook.com/profile.php?id=' . rawurlencode($locator);
        }

        return 'https://www.facebook.com/' . rawurlencode($locator);
    }

    public static function linkedinBootstrapFromLocator(string $locator): string
    {
        $locator = trim($locator);
        if (preg_match('#^(company|showcase)/(.+)$#i', $locator, $matches)) {
            $kind = strtolower($matches[1]);
            $id = trim($matches[2]);
            if ($id === '') {
                throw new \InvalidArgumentException('LinkedIn page id is required.');
            }

            return self::linkedinAdminUrl($kind, $id);
        }

        // Legacy rows stored before company/showcase prefix (always showcase).
        return self::linkedinAdminUrl('showcase', $locator);
    }

    private static function linkedinAdminUrl(string $kind, string $id): string
    {
        if (!in_array($kind, ['company', 'showcase'], true)) {
            throw new \InvalidArgumentException('LinkedIn page kind must be company or showcase.');
        }

        return 'https://www.linkedin.com/' . $kind . '/' . rawurlencode($id) . '/admin/page-posts/published';
    }

    /**
     * @param array<string, mixed> $account Row with platform, account_kind, sub_page_id from joined query
     */
    public static function bootstrapUrlForAccount(array $account): string
    {
        return self::bootstrapUrl(
            (string) $account['platform'],
            (string) $account['account_kind'],
            isset($account['sub_page_id']) ? (string) $account['sub_page_id'] : null
        );
    }

    private static function normalizeFacebookSubPageLocator(string $input): string
    {
        if (preg_match('#^https?://#i', $input) || str_contains($input, 'facebook.com')) {
            return self::parseFacebookPageUrl($input);
        }

        if (str_starts_with($input, 'profile.php')) {
            return self::parseFacebookPageUrl('https://www.facebook.com/' . ltrim($input, '/'));
        }

        if (preg_match('/^\d+$/', $input)) {
            return $input;
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $input)) {
            throw new \InvalidArgumentException('Invalid Facebook page username.');
        }

        $slug = $input;
        if (in_array(strtolower($slug), self::FACEBOOK_RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException('Invalid Facebook page username.');
        }

        return $slug;
    }

    private static function parseFacebookPageUrl(string $input): string
    {
        $url = str_contains($input, '://') ? $input : 'https://' . ltrim($input, '/');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid Facebook page URL.');
        }

        $host = strtolower((string) $parts['host']);
        if (!str_ends_with($host, 'facebook.com')) {
            throw new \InvalidArgumentException('URL must be a facebook.com page link.');
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === 'profile.php' || str_ends_with($path, '/profile.php')) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = trim((string) ($query['id'] ?? ''));
            if ($id === '' || !preg_match('/^\d+$/', $id)) {
                throw new \InvalidArgumentException('Facebook page URL must include a numeric page id.');
            }

            return $id;
        }

        if ($path === '' || str_contains($path, '/')) {
            throw new \InvalidArgumentException('Invalid Facebook page URL path.');
        }

        $blocked = ['groups', 'events', 'watch', 'marketplace', 'photo.php', 'story.php', 'share'];
        foreach ($blocked as $segment) {
            if (str_starts_with(strtolower($path), $segment)) {
                throw new \InvalidArgumentException('URL must point to a Facebook page, not a group or event.');
            }
        }

        if (in_array(strtolower($path), self::FACEBOOK_RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException('Invalid Facebook page URL.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $path)) {
            throw new \InvalidArgumentException('Invalid Facebook page username in URL.');
        }

        return $path;
    }

    private static function normalizeLinkedInSubPageLocator(string $input): string
    {
        if (preg_match('#^https?://#i', $input) || str_contains($input, 'linkedin.com')) {
            return self::parseLinkedInPageUrl($input);
        }

        if (preg_match('#^(company|showcase)[:/](.+)$#i', $input, $matches)) {
            return self::canonicalLinkedInLocator(strtolower($matches[1]), trim($matches[2]));
        }

        if (preg_match('/^\d+$/', $input)) {
            throw new \InvalidArgumentException(
                'LinkedIn numeric page id is ambiguous; use company/ID, showcase/ID, or paste the full admin URL.'
            );
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $input)) {
            throw new \InvalidArgumentException('Invalid LinkedIn page id.');
        }

        return self::canonicalLinkedInLocator('showcase', $input);
    }

    private static function parseLinkedInPageUrl(string $input): string
    {
        $url = str_contains($input, '://') ? $input : 'https://' . ltrim($input, '/');
        if (preg_match('~/(company|showcase)/([^/?#]+)~i', $url, $matches)) {
            return self::canonicalLinkedInLocator(strtolower($matches[1]), rawurldecode($matches[2]));
        }

        throw new \InvalidArgumentException(
            'LinkedIn URL must be a company or showcase admin link (e.g. /company/…/admin/ or /showcase/…/admin/).'
        );
    }

    private static function canonicalLinkedInLocator(string $kind, string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('LinkedIn page id is required.');
        }

        if (!in_array($kind, ['company', 'showcase'], true)) {
            throw new \InvalidArgumentException('LinkedIn page kind must be company or showcase.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
            throw new \InvalidArgumentException('Invalid LinkedIn page id.');
        }

        return $kind . '/' . $id;
    }
}
