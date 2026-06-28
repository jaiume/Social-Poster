<?php

declare(strict_types=1);

namespace App\Services;

class FetchAgentToolkit
{
    public function __construct(private readonly SourceFetchService $fetchService)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $urlParam = [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Full HTTPS URL on an allowed domain'],
            ],
            'required' => ['url'],
        ];

        $pageUrlsParam = [
            'type' => 'object',
            'properties' => [
                'urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'One or more full HTTPS URLs on allowed domains to fetch together.',
                    'minItems' => 1,
                    'maxItems' => 10,
                ],
            ],
            'required' => ['urls'],
        ];

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch_page',
                    'description' => 'Fetch one or more web pages and return HTML (scripts removed), title, same-domain links, and image URLs for each page.',
                    'parameters' => $pageUrlsParam,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'url_sitemap_children',
                    'description' => 'Given a URL, crawl same-domain pages linked beneath that URL path and return a tree of page URLs and titles. Use to map site structure before fetching individual pages.',
                    'parameters' => $urlParam,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch_resource',
                    'description' => 'Fetch a text resource such as CSS, JavaScript, JSON, or XML from an allowed URL.',
                    'parameters' => $urlParam,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch_image',
                    'description' => 'Fetch an image from an allowed URL for visual analysis. Use image URLs discovered via fetch_page.',
                    'parameters' => $urlParam,
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @param array<int, array<string, mixed>> $messages
     * @param string[] $rootUrls
     * @return array{tool_call_count: int, audit: array<int, array<string, mixed>>}
     */
    public function processToolCalls(
        array $toolCalls,
        array &$messages,
        array $rootUrls,
        int $toolCallCount,
        int $maxToolCalls
    ): array {
        $audit = [];
        $visionImages = [];

        foreach ($toolCalls as $tc) {
            if ($toolCallCount >= $maxToolCalls) {
                throw new \RuntimeException('Max tool calls exceeded.');
            }
            $toolCallCount++;

            $fn = $tc['function']['name'] ?? '';
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $result = $this->execute($fn, $args, $rootUrls);
            $audit[] = [
                'tool' => $fn,
                'args' => $args,
                'result' => $this->redactResultForAudit($result),
            ];

            if ($fn === 'fetch_image' && !empty($result['success']) && !empty($result['data_url'])) {
                $visionImages[] = $result;
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => json_encode([
                        'success' => true,
                        'url' => $result['url'],
                        'content_type' => $result['content_type'],
                        'bytes' => $result['bytes'],
                        'note' => 'Image attached below for visual analysis.',
                    ], JSON_THROW_ON_ERROR),
                ];
                continue;
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'],
                'content' => $this->encodeForToolMessage($result),
            ];
        }

        foreach ($visionImages as $image) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Fetched image from: ' . $image['url']],
                    ['type' => 'image_url', 'image_url' => ['url' => $image['data_url']]],
                ],
            ];
        }

        return ['tool_call_count' => $toolCallCount, 'audit' => $audit];
    }

    /**
     * @param array<string, mixed> $args
     * @param string[] $rootUrls
     * @return array<string, mixed>
     */
    public function execute(string $fn, array $args, array $rootUrls): array
    {
        $url = (string) ($args['url'] ?? '');

        return match ($fn) {
            'fetch_page' => $this->fetchService->fetchPages($this->normalizeFetchPageUrls($args), $rootUrls),
            'url_sitemap_children' => $this->fetchService->urlSitemapChildren($url, $rootUrls),
            'fetch_resource' => $this->fetchService->fetchResource($url, $rootUrls),
            'fetch_image' => $this->fetchService->fetchImage($url, $rootUrls),
            default => ['success' => false, 'error' => 'Unknown tool'],
        };
    }

    /**
     * @param array<string, mixed> $args
     * @return string[]
     */
    private function normalizeFetchPageUrls(array $args): array
    {
        if (!empty($args['urls']) && is_array($args['urls'])) {
            return array_values(array_filter(array_map('strval', $args['urls'])));
        }

        $url = (string) ($args['url'] ?? '');

        return $url !== '' ? [$url] : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeForToolMessage(array $data): string
    {
        return json_encode(
            $this->utf8Safe($data),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array<string, mixed>|string|int|float|bool|null
     */
    private function utf8Safe(mixed $value): mixed
    {
        if (is_string($value)) {
            if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }

            $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            return is_string($clean) ? $clean : '';
        }

        if (!is_array($value)) {
            return $value;
        }

        $safe = [];
        foreach ($value as $key => $item) {
            $safe[$key] = $this->utf8Safe($item);
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function redactResultForAudit(array $result): array
    {
        if (isset($result['pages']) && is_array($result['pages'])) {
            $result['pages'] = array_map(
                fn (array $page): array => $this->redactResultForAudit($page),
                $result['pages']
            );
        }

        if (isset($result['data_url'])) {
            $result['data_url'] = '[omitted]';
        }
        if (isset($result['html']) && is_string($result['html']) && strlen($result['html']) > 500) {
            $result['html'] = substr($result['html'], 0, 500) . '… [truncated in audit]';
        }
        if (isset($result['body']) && is_string($result['body']) && strlen($result['body']) > 500) {
            $result['body'] = substr($result['body'], 0, 500) . '… [truncated in audit]';
        }
        if (isset($result['tree'])) {
            $result['tree'] = '[tree omitted from audit]';
        }

        return $result;
    }
}
