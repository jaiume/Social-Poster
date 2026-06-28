<?php

declare(strict_types=1);

namespace App\Services;

class OpenRouterClient
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const MAX_TOKENS = 8192;

    public function __construct(private readonly AppSettingsService $settings)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $model = null,
        ?int $postId = null
    ): array {
        $apiKey = $this->settings->get('openrouter_api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }

        $body = [
            'model' => $model ?? $this->settings->get('openrouter_model', 'openai/gpt-4o-mini'),
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        return $this->postCompletion($body, $apiKey, $postId, 120);
    }

    /**
     * @param array<int, array{label: string, data_url: string}> $referenceImages
     */
    public function generateImage(
        string $systemPrompt,
        string $userPrompt,
        array $referenceImages = [],
        ?int $postId = null
    ): ?string {
        $apiKey = $this->settings->get('openrouter_api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }

        $userContent = trim($userPrompt);
        if (trim($systemPrompt) !== '') {
            $userContent = trim($systemPrompt) . "\n\n" . $userContent;
        }

        $body = [
            'model' => $this->settings->get('openrouter_image_model', 'black-forest-labs/flux-1.1-schnell'),
            'messages' => [
                ['role' => 'user', 'content' => $this->buildImageUserContent($userContent, $referenceImages)],
            ],
            'modalities' => ['image'],
        ];

        $data = $this->postCompletion($body, $apiKey, $postId, 180);

        return $this->extractImageBytes($data);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postCompletion(array $body, string $apiKey, ?int $postId, int $timeout): array
    {
        $sessionId = $this->sessionIdForPost($postId);
        if ($sessionId !== null) {
            $body['session_id'] = $sessionId;
        }
        // Keep requests within available credit budget and avoid oversized completions.
        $body['max_tokens'] = $this->normalizeMaxTokens($body['max_tokens'] ?? null);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->requestHeaders($apiKey, $sessionId),
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('OpenRouter request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $response) : $response;
            throw new \RuntimeException('OpenRouter error: ' . $msg);
        }

        return $data;
    }

    private function normalizeMaxTokens(mixed $value): int
    {
        $requested = is_numeric($value) ? (int) $value : self::MAX_TOKENS;
        if ($requested <= 0) {
            return self::MAX_TOKENS;
        }

        return min($requested, self::MAX_TOKENS);
    }

    /**
     * @return string[]
     */
    private function requestHeaders(string $apiKey, ?string $sessionId): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: ' . $this->appReferer(),
            'X-OpenRouter-Title: ' . $this->appTitle(),
        ];

        if ($sessionId !== null) {
            $headers[] = 'X-Session-Id: ' . $sessionId;
        }

        return $headers;
    }

    private function appTitle(): string
    {
        return (string) ConfigService::get('app.name', 'Social-Poster');
    }

    private function appReferer(): string
    {
        $configured = trim((string) ConfigService::get('app.openrouter_referer', ''));
        if ($configured !== '') {
            return $configured;
        }

        $name = strtolower(trim($this->appTitle()));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
        $slug = trim($slug, '-');

        return 'https://' . ($slug !== '' ? $slug : 'social-poster');
    }

    private function sessionIdForPost(?int $postId): ?string
    {
        if ($postId === null || $postId <= 0) {
            return null;
        }

        return 'post-' . $postId;
    }

    /**
     * @param array<int, array{label: string, data_url: string}> $referenceImages
     * @return string|array<int, array<string, mixed>>
     */
    private function buildImageUserContent(string $userContent, array $referenceImages): string|array
    {
        if ($referenceImages === []) {
            return $userContent;
        }

        $parts = [
            [
                'type' => 'text',
                'text' => 'Use the reference images below as brand assets (logos, UI, product shots). '
                    . 'Incorporate them faithfully into the generated social media image where appropriate.',
            ],
        ];

        foreach ($referenceImages as $reference) {
            $label = trim((string) ($reference['label'] ?? ''));
            if ($label !== '') {
                $parts[] = [
                    'type' => 'text',
                    'text' => 'Reference: ' . $label,
                ];
            }
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => (string) $reference['data_url']],
            ];
        }

        $parts[] = [
            'type' => 'text',
            'text' => $userContent,
        ];

        return $parts;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractImageBytes(array $data): ?string
    {
        $message = $data['choices'][0]['message'] ?? [];

        foreach ($message['images'] ?? [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = $image['image_url']['url'] ?? $image['url'] ?? '';
            $bytes = $this->decodeImageSource((string) $url);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        $content = $message['content'] ?? '';
        if (is_array($content)) {
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'image_url') {
                    $bytes = $this->decodeImageSource((string) ($part['image_url']['url'] ?? ''));
                    if ($bytes !== null) {
                        return $bytes;
                    }
                }
            }
        }

        if (is_string($content)) {
            return $this->decodeImageSource($content);
        }

        return null;
    }

    private function decodeImageSource(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $source, $matches)) {
            $decoded = base64_decode($matches[1], true);

            return $decoded !== false ? $decoded : null;
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $data = @file_get_contents($source);

            return $data !== false ? $data : null;
        }

        return null;
    }
}
