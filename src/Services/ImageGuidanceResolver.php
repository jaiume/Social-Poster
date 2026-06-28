<?php

declare(strict_types=1);

namespace App\Services;

class ImageGuidanceResolver
{
    private const MAX_REFERENCE_IMAGES = 4;
    private const MAX_IMAGES_PER_PAGE = 2;
    private const MAX_PAGES = 3;
    private const MAX_PAGE_TEXT_CHARS = 600;

    public function __construct(private readonly SourceFetchService $fetchService)
    {
    }

    /**
     * @return array{
     *     reference_images: array<int, array{label: string, data_url: string}>,
     *     page_context: string
     * }
     */
    public function resolve(string $imageGuidance): array
    {
        $imageGuidance = trim($imageGuidance);
        if ($imageGuidance === '') {
            return ['reference_images' => [], 'page_context' => ''];
        }

        $allowedUrls = $this->extractUrls($imageGuidance);
        if ($allowedUrls === []) {
            return ['reference_images' => [], 'page_context' => ''];
        }

        $references = [];
        $pageContextLines = [];
        $pagesFetched = 0;

        foreach ($this->extractUrls($imageGuidance) as $url) {
            if (count($references) >= self::MAX_REFERENCE_IMAGES) {
                break;
            }

            if ($this->isLikelyDirectImageUrl($url)) {
                $this->addImageReference($references, $url, $allowedUrls, $url);
                continue;
            }

            if ($pagesFetched >= self::MAX_PAGES) {
                continue;
            }

            $page = $this->fetchService->fetchPage($url, $allowedUrls);
            if (empty($page['success'])) {
                continue;
            }

            $pagesFetched++;
            $pageContextLines[] = $this->formatPageContext($url, $page);

            $remaining = self::MAX_REFERENCE_IMAGES - count($references);
            $imageBudget = min(self::MAX_IMAGES_PER_PAGE, $remaining);
            foreach ($this->prioritizePageImageUrls($page) as $imageUrl) {
                if ($imageBudget <= 0) {
                    break;
                }
                if ($this->addImageReference($references, $imageUrl, $allowedUrls, $url)) {
                    $imageBudget--;
                }
            }
        }

        return [
            'reference_images' => array_values($references),
            'page_context' => trim(implode("\n\n", $pageContextLines)),
        ];
    }

    /**
     * @return string[]
     */
    public function extractUrls(string $text): array
    {
        if (!preg_match_all('#https://[^\s<>"\')\]]+#i', $text, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $urls[] = rtrim($url, '.,;');
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, array{url: string, label: null}>
     */
    public function sourcesFromGuidance(string $guidance): array
    {
        return array_map(
            fn (string $url) => ['url' => $url, 'label' => null],
            $this->extractUrls($guidance)
        );
    }

    public function isLikelyDirectImageUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.avif'] as $extension) {
            if (str_ends_with($path, $extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{label: string, data_url: string}> $references
     */
    private function addImageReference(array &$references, string $imageUrl, array $allowedUrls, string $label): bool
    {
        if (isset($references[$imageUrl])) {
            return false;
        }

        $result = $this->fetchService->fetchImage($imageUrl, $allowedUrls);
        if (empty($result['success']) || empty($result['data_url'])) {
            return false;
        }

        $references[$imageUrl] = [
            'label' => $label,
            'data_url' => (string) $result['data_url'],
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function formatPageContext(string $url, array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        $html = (string) ($page['html'] ?? '');
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if (strlen($text) > self::MAX_PAGE_TEXT_CHARS) {
            $text = substr($text, 0, self::MAX_PAGE_TEXT_CHARS) . '…';
        }

        $lines = ["Referenced page: {$url}"];
        if ($title !== '') {
            $lines[] = "Title: {$title}";
        }
        if ($text !== '') {
            $lines[] = "Summary: {$text}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $page
     * @return string[]
     */
    private function prioritizePageImageUrls(array $page): array
    {
        $html = (string) ($page['html'] ?? '');
        $baseUrl = (string) ($page['url'] ?? '');
        $ordered = [];

        foreach ($this->extractOgImageUrls($html, $baseUrl) as $url) {
            $ordered[] = $url;
        }

        foreach ($page['images'] ?? [] as $url) {
            if (is_string($url) && $url !== '') {
                $ordered[] = $url;
            }
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @return string[]
     */
    private function extractOgImageUrls(string $html, string $baseUrl): array
    {
        $patterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
        ];

        $urls = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $html, $matches)) {
                continue;
            }
            foreach ($matches[1] as $src) {
                $urls[] = $this->resolveUrl($baseUrl, (string) $src);
            }
        }

        return array_values(array_filter($urls, fn ($url) => str_starts_with($url, 'https://')));
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$relative}";
        }

        $path = $parts['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        return "{$scheme}://{$host}{$dir}/" . ltrim($relative, '/');
    }
}
