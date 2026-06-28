<?php

declare(strict_types=1);

namespace App\Services;

class SourceFetchService
{
    private const MAX_RAW_BYTES = 524288;
    private const MAX_HTML_CHARS = 48000;
    private const MAX_RESOURCE_CHARS = 32000;
    private const MAX_IMAGE_BYTES = 1048576;
    private const MAX_SITEMAP_PAGES = 35;
    private const MAX_SITEMAP_DEPTH = 4;
    private const MAX_FETCH_PAGES = 10;

    /**
     * @param string[] $allowedRootUrls
     */
    public function fetchPage(string $url, array $allowedRootUrls): array
    {
        $raw = $this->httpGet($url, $allowedRootUrls);
        if (!$raw['success']) {
            return $raw;
        }

        $contentType = $this->normalizeContentType((string) ($raw['content_type'] ?? ''));
        if (!$this->isLikelyHtmlPage($url, $contentType)) {
            return [
                'success' => false,
                'error' => 'URL is not an HTML page. Use fetch_image for images or fetch_resource for text assets.',
                'url' => $url,
                'content_type' => $contentType,
            ];
        }

        $body = (string) $raw['body'];
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
            $title = $this->ensureUtf8(trim(html_entity_decode(strip_tags($m[1]))));
        }

        $html = $this->ensureUtf8($this->sanitizeHtml($body));
        if (strlen($html) > self::MAX_HTML_CHARS) {
            $html = substr($html, 0, self::MAX_HTML_CHARS) . "\n<!-- truncated -->";
        }

        return [
            'success' => true,
            'url' => $url,
            'title' => $title,
            'html' => $html,
            'links' => array_slice($this->extractSameDomainLinks($body, $url), 0, 30),
            'images' => array_slice($this->extractImageUrls($body, $url), 0, 30),
        ];
    }

    /**
     * @param string[] $urls
     * @param string[] $allowedRootUrls
     * @return array<string, mixed>
     */
    public function fetchPages(array $urls, array $allowedRootUrls): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        if ($urls === []) {
            return ['success' => false, 'error' => 'No URLs provided.', 'pages' => []];
        }
        if (count($urls) > self::MAX_FETCH_PAGES) {
            return [
                'success' => false,
                'error' => 'Too many URLs (max ' . self::MAX_FETCH_PAGES . ').',
                'pages' => [],
            ];
        }

        $pages = [];
        foreach ($urls as $url) {
            $pages[] = $this->fetchPage($url, $allowedRootUrls);
        }

        $fetched = count(array_filter($pages, static fn (array $page): bool => !empty($page['success'])));

        return [
            'success' => $fetched > 0,
            'pages' => $pages,
            'fetched' => $fetched,
            'failed' => count($pages) - $fetched,
        ];
    }

    /**
     * @param string[] $allowedRootUrls
     */
    public function fetchResource(string $url, array $allowedRootUrls): array
    {
        $raw = $this->httpGet($url, $allowedRootUrls);
        if (!$raw['success']) {
            return $raw;
        }

        $contentType = $this->normalizeContentType((string) $raw['content_type']);
        if (!$this->isTextResource($contentType, $url)) {
            return [
                'success' => false,
                'error' => 'Resource is not a text type. Use fetch_image for images.',
                'url' => $url,
                'content_type' => $contentType,
            ];
        }

        $body = $this->ensureUtf8((string) $raw['body']);
        if (strlen($body) > self::MAX_RESOURCE_CHARS) {
            $body = substr($body, 0, self::MAX_RESOURCE_CHARS) . "\n/* truncated */";
        }

        return [
            'success' => true,
            'url' => $url,
            'content_type' => $contentType,
            'body' => $body,
        ];
    }

    /**
     * @param string[] $allowedRootUrls
     */
    public function fetchImage(string $url, array $allowedRootUrls): array
    {
        $raw = $this->httpGet($url, $allowedRootUrls);
        if (!$raw['success']) {
            return $raw;
        }

        $contentType = $this->normalizeContentType((string) $raw['content_type']);
        if (!$this->isImageContentType($contentType, $url)) {
            return [
                'success' => false,
                'error' => 'URL did not return an image.',
                'url' => $url,
                'content_type' => $contentType,
            ];
        }

        $body = (string) $raw['body'];
        if (strlen($body) > self::MAX_IMAGE_BYTES) {
            return [
                'success' => false,
                'error' => 'Image exceeds size limit (' . self::MAX_IMAGE_BYTES . ' bytes).',
                'url' => $url,
            ];
        }

        $mime = explode(';', $contentType)[0];
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($body);

        return [
            'success' => true,
            'url' => $url,
            'content_type' => $mime,
            'bytes' => strlen($body),
            'data_url' => $dataUrl,
        ];
    }

    /**
     * Crawl same-domain pages under a URL path prefix and return a link tree.
     *
     * @param string[] $allowedRootUrls
     * @return array<string, mixed>
     */
    public function urlSitemapChildren(string $url, array $allowedRootUrls): array
    {
        if (!$this->isUrlAllowed($url, $allowedRootUrls)) {
            return ['success' => false, 'error' => 'URL not allowed for this profile.', 'url' => $url];
        }

        $rootUrl = $this->normalizeSitemapUrl($url);
        $nodes = [];
        $visited = [];
        $queue = [[$rootUrl, 0]];
        $pagesCrawled = 0;
        $truncated = false;

        while ($queue !== []) {
            if ($pagesCrawled >= self::MAX_SITEMAP_PAGES) {
                $truncated = true;
                break;
            }

            [$currentUrl, $depth] = array_shift($queue);
            if (isset($visited[$currentUrl])) {
                continue;
            }
            if ($depth > self::MAX_SITEMAP_DEPTH) {
                $truncated = true;
                continue;
            }

            $visited[$currentUrl] = true;
            $raw = $this->httpGet($currentUrl, $allowedRootUrls);
            $pagesCrawled++;

            if (!$raw['success']) {
                $nodes[$currentUrl] = [
                    'url' => $currentUrl,
                    'title' => null,
                    'child_urls' => [],
                    'error' => $raw['error'] ?? 'Failed to fetch URL.',
                ];
                continue;
            }

            $contentType = $this->normalizeContentType((string) ($raw['content_type'] ?? ''));
            if (!$this->isLikelyHtmlPage($currentUrl, $contentType)) {
                $nodes[$currentUrl] = [
                    'url' => $currentUrl,
                    'title' => null,
                    'child_urls' => [],
                ];
                continue;
            }

            $body = (string) $raw['body'];
            $title = '';
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1])));
            }

            $childUrls = [];
            foreach ($this->extractSameDomainLinks($body, $currentUrl) as $link) {
                if (!$this->isUrlAllowed($link, $allowedRootUrls)) {
                    continue;
                }
                if (!$this->isUnderPathPrefix($link, $rootUrl)) {
                    continue;
                }
                $normalized = $this->normalizeSitemapUrl($link);
                if ($normalized === $currentUrl || !$this->isSitemapPageUrl($normalized)) {
                    continue;
                }
                $childUrls[] = $normalized;
                if (!isset($visited[$normalized]) && $depth < self::MAX_SITEMAP_DEPTH) {
                    $queue[] = [$normalized, $depth + 1];
                }
            }

            $nodes[$currentUrl] = [
                'url' => $currentUrl,
                'title' => $title !== '' ? $title : null,
                'child_urls' => array_values(array_unique($childUrls)),
            ];
        }

        if ($queue !== []) {
            $truncated = true;
        }

        return [
            'success' => true,
            'url' => $rootUrl,
            'tree' => $this->buildSitemapTree($rootUrl, $nodes),
            'stats' => [
                'pages_crawled' => $pagesCrawled,
                'unique_urls' => count($nodes),
                'truncated' => $truncated,
                'max_depth' => self::MAX_SITEMAP_DEPTH,
                'max_pages' => self::MAX_SITEMAP_PAGES,
            ],
        ];
    }

    /**
     * @param string[] $allowedRootUrls
     */
    public function isUrlAllowed(string $url, array $allowedRootUrls): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        if ($this->isBlockedHost($host)) {
            return false;
        }

        foreach ($allowedRootUrls as $root) {
            $rootParts = parse_url($root);
            if ($rootParts === false || empty($rootParts['host'])) {
                continue;
            }
            $rootHost = strtolower($rootParts['host']);
            if ($this->hostsMatch($host, $rootHost)) {
                return true;
            }
        }

        return false;
    }

    private function hostsMatch(string $host, string $rootHost): bool
    {
        if ($host === $rootHost) {
            return true;
        }

        $rootBase = $this->registrableDomain($rootHost);
        if ($rootBase !== '' && ($host === $rootBase || str_ends_with($host, '.' . $rootBase))) {
            return true;
        }

        return str_ends_with($host, '.' . $rootHost);
    }

    private function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return $host;
        }

        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }

    /**
     * @param string[] $allowedRootUrls
     * @return array<string, mixed>
     */
    private function httpGet(string $url, array $allowedRootUrls): array
    {
        if (!$this->isUrlAllowed($url, $allowedRootUrls)) {
            return ['success' => false, 'error' => 'URL not allowed for this profile.', 'url' => $url];
        }

        if (function_exists('curl_init')) {
            return $this->httpGetCurl($url);
        }

        return $this->httpGetStream($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetCurl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'SocialPoster/1.0 (+internal)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => $error ?: 'Failed to fetch URL.', 'url' => $url];
        }
        if ($httpCode >= 400) {
            return ['success' => false, 'error' => 'HTTP ' . $httpCode, 'url' => $url];
        }
        if (strlen($body) > self::MAX_RAW_BYTES) {
            $body = substr($body, 0, self::MAX_RAW_BYTES);
        }

        return [
            'success' => true,
            'body' => $body,
            'content_type' => $contentType,
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetStream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'SocialPoster/1.0 (+internal)',
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_RAW_BYTES);
        if ($body === false) {
            return ['success' => false, 'error' => 'Failed to fetch URL.', 'url' => $url];
        }

        $contentType = 'application/octet-stream';
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, 13));
                    break;
                }
            }
        }

        return [
            'success' => true,
            'body' => $body,
            'content_type' => $contentType,
            'url' => $url,
        ];
    }

    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html) ?? $html;

        return trim($html);
    }

    private function normalizeContentType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0]));
    }

    private function isTextResource(string $contentType, string $url): bool
    {
        if (str_starts_with($contentType, 'text/')) {
            return true;
        }

        $textTypes = [
            'application/json',
            'application/javascript',
            'application/xml',
            'application/xhtml+xml',
        ];
        if (in_array($contentType, $textTypes, true)) {
            return true;
        }

        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
        foreach (['.css', '.js', '.json', '.xml', '.svg', '.txt', '.md'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }

    private function isImageContentType(string $contentType, string $url): bool
    {
        if (str_starts_with($contentType, 'image/')) {
            return true;
        }

        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
        foreach (['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.avif'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return true;
        }
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * @return string[]
     */
    private function extractSameDomainLinks(string $html, string $baseUrl): array
    {
        $base = parse_url($baseUrl);
        $host = strtolower($base['host'] ?? '');
        $links = [];
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                    continue;
                }
                $absolute = $this->resolveUrl($baseUrl, $href);
                $p = parse_url($absolute);
                if ($p && strtolower($p['host'] ?? '') === $host && str_starts_with($absolute, 'https://')) {
                    $links[] = $absolute;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * @return string[]
     */
    private function extractImageUrls(string $html, string $baseUrl): array
    {
        $images = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $images[] = $this->resolveUrl($baseUrl, $src);
            }
        }

        if (preg_match_all('/<img[^>]+srcset=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $srcset) {
                foreach (preg_split('/\s*,\s*/', $srcset) ?: [] as $part) {
                    $url = trim(explode(' ', $part)[0] ?? '');
                    if ($url !== '') {
                        $images[] = $this->resolveUrl($baseUrl, $url);
                    }
                }
            }
        }

        $metaPatterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
        ];
        foreach ($metaPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $src) {
                    $images[] = $this->resolveUrl($baseUrl, $src);
                }
            }
        }

        $filtered = [];
        foreach ($images as $image) {
            if (str_starts_with($image, 'https://')) {
                $filtered[] = $image;
            }
        }

        return array_values(array_unique($filtered));
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        $host = $b['host'] ?? '';
        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$relative}";
        }

        $path = $b['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        return "{$scheme}://{$host}{$dir}/" . ltrim($relative, '/');
    }

    private function normalizeSitemapUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return "{$scheme}://{$host}{$path}";
    }

    private function isUnderPathPrefix(string $url, string $rootUrl): bool
    {
        $urlParts = parse_url($url);
        $rootParts = parse_url($rootUrl);
        if ($urlParts === false || $rootParts === false) {
            return false;
        }

        $urlHost = strtolower($urlParts['host'] ?? '');
        $rootHost = strtolower($rootParts['host'] ?? '');
        if ($urlHost === '' || $rootHost === '' || !$this->hostsMatch($urlHost, $rootHost)) {
            return false;
        }

        $urlPath = $urlParts['path'] ?? '/';
        if ($urlPath === '') {
            $urlPath = '/';
        }
        if ($urlPath !== '/' && str_ends_with($urlPath, '/')) {
            $urlPath = rtrim($urlPath, '/');
        }

        $rootPath = $rootParts['path'] ?? '/';
        if ($rootPath === '') {
            $rootPath = '/';
        }
        if ($rootPath !== '/' && str_ends_with($rootPath, '/')) {
            $rootPath = rtrim($rootPath, '/');
        }

        if ($rootPath === '/') {
            return true;
        }

        return $urlPath === $rootPath || str_starts_with($urlPath, $rootPath . '/');
    }

    private function isLikelyHtmlPage(string $url, string $contentType): bool
    {
        if ($this->isNonHtmlAssetPath($url)) {
            return false;
        }

        if (str_contains($contentType, 'html') || $contentType === '') {
            return true;
        }

        return str_contains($contentType, 'text/') || $contentType === 'application/xhtml+xml';
    }

    private function isSitemapPageUrl(string $url): bool
    {
        return !$this->isNonHtmlAssetPath($url);
    }

    private function isNonHtmlAssetPath(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '/');
        foreach ([
            '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.avif', '.ico',
            '.pdf', '.css', '.js', '.json', '.xml', '.zip', '.woff', '.woff2',
            '.ttf', '.eot', '.mp4', '.webm', '.mp3', '.wav',
        ] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }

    private function ensureUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return is_string($clean) ? $clean : '';
    }

    private function sitemapPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if ($path === '') {
            return '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $path;
    }

    private function isSitemapChildUrl(string $parentUrl, string $childUrl): bool
    {
        if ($parentUrl === $childUrl) {
            return false;
        }

        $parentPath = $this->sitemapPath($parentUrl);
        $childPath = $this->sitemapPath($childUrl);

        if ($parentPath === '/') {
            return $childPath !== '/';
        }

        return str_starts_with($childPath, $parentPath . '/');
    }

    /**
     * @param array<string, array{url: string, title: ?string, child_urls: string[]}> $nodes
     * @param array<string, true> $ancestry
     * @return array<string, mixed>
     */
    private function buildSitemapTree(string $url, array $nodes, array $ancestry = []): array
    {
        $node = $nodes[$url] ?? ['url' => $url, 'title' => null, 'child_urls' => []];

        $tree = [
            'url' => $url,
            'title' => $node['title'] ?? null,
            'children' => [],
        ];

        if (isset($node['error'])) {
            $tree['error'] = $node['error'];
        }

        $nextAncestry = $ancestry;
        $nextAncestry[$url] = true;

        foreach ($node['child_urls'] as $childUrl) {
            if (isset($nextAncestry[$childUrl]) || !$this->isSitemapPageUrl($childUrl)) {
                continue;
            }
            if (!$this->isSitemapChildUrl($url, $childUrl)) {
                continue;
            }

            if (!isset($nodes[$childUrl])) {
                $tree['children'][] = [
                    'url' => $childUrl,
                    'title' => null,
                    'children' => [],
                ];
                continue;
            }

            $tree['children'][] = $this->buildSitemapTree($childUrl, $nodes, $nextAncestry);
        }

        return $tree;
    }
}
