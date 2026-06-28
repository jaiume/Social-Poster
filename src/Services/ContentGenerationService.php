<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;
use App\Support\PostContentParser;
use App\Support\ServiceResult;

class ContentGenerationService
{
    public function __construct(
        private readonly PostDao $postDao,
        private readonly ProductProfileDao $profileDao,
        private readonly AppSettingsService $settings,
        private readonly OpenRouterClient $openRouter,
        private readonly FetchAgentToolkit $fetchToolkit,
        private readonly ImageGuidanceResolver $guidanceResolver
    ) {
    }

    public function createPostForGeneration(int $profileId): int
    {
        $profile = $this->profileDao->findById($profileId);
        $sources = $this->sourcesFromProfile($profile ?? []);

        return $this->postDao->create([
            'product_profile_id' => $profileId,
            'status' => 'draft',
            'source_urls_json' => json_encode($sources, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function generateContentOnly(int $postId, int $profileId, array $profile): array
    {
        $sources = $this->sourcesFromProfile($profile);

        return $this->generateIntoPost($postId, $profileId, $profile, $sources);
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function regenerateContentOnly(int $postId): array
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ServiceResult::failure('Post not found.', 'NOT_FOUND');
        }

        $profileId = (int) $post['product_profile_id'];
        $profile = $this->profileDao->findById($profileId);
        if ($profile === null) {
            return ServiceResult::failure('Profile not found.', 'NOT_FOUND');
        }

        if (($post['status'] ?? '') !== 'draft') {
            return ServiceResult::failure('Only draft posts can be regenerated.', 'INVALID_STATE');
        }

        $sources = $this->sourcesFromProfile($profile);
        $this->postDao->update($postId, [
            'status' => 'draft',
            'content_facebook' => null,
            'content_linkedin' => null,
            'image_path' => null,
            'image_error' => null,
            'ai_error' => null,
            'ai_model' => null,
            'ai_prompt_snapshot' => null,
            'ai_tool_calls_json' => null,
            'generated_at' => null,
            'source_urls_json' => json_encode($sources, JSON_THROW_ON_ERROR),
        ]);

        return $this->generateIntoPost($postId, $profileId, $profile, $sources);
    }

    /**
     * @deprecated Use task engine recipes instead.
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function generate(int $profileId, array $profile): array
    {
        $postId = $this->createPostForGeneration($profileId);

        return $this->generateContentOnly($postId, $profileId, $profile);
    }

    /**
     * @deprecated Use task engine recipes instead.
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function regenerate(int $postId): array
    {
        return $this->regenerateContentOnly($postId);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, array{url: string, label: null}>
     */
    private function sourcesFromProfile(array $profile): array
    {
        return $this->guidanceResolver->sourcesFromGuidance((string) ($profile['posting_guidance'] ?? ''));
    }

    /**
     * @param array<int, array{url: string, label: null}> $sources
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    private function generateIntoPost(int $postId, int $profileId, array $profile, array $sources): array
    {
        $rootUrls = array_map(fn ($s) => $s['url'], $sources);
        $maxTurns = $this->settings->getInt('openrouter_max_agent_turns', 15);
        $maxToolCalls = $this->settings->getInt('openrouter_max_tool_calls', 10);
        $toolCallCount = 0;
        $audit = [];

        $systemPrompt = $this->postSystemPrompt();
        $userPrompt = $this->buildUserPrompt($profile, $sources, $profileId);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $tools = $this->fetchToolkit->toolDefinitions();

        try {
            for ($turn = 0; $turn < $maxTurns; $turn++) {
                $response = $this->openRouter->chat($messages, $tools, null, $postId);
                $choice = $response['choices'][0]['message'] ?? [];
                $messages[] = $choice;

                $toolCalls = $choice['tool_calls'] ?? [];
                if ($toolCalls !== []) {
                    $processed = $this->fetchToolkit->processToolCalls(
                        $toolCalls,
                        $messages,
                        $rootUrls,
                        $toolCallCount,
                        $maxToolCalls
                    );
                    $toolCallCount = $processed['tool_call_count'];
                    foreach ($processed['audit'] as $entry) {
                        $audit[] = $entry;
                    }
                    continue;
                }

                $content = (string) ($choice['content'] ?? '');
                $parsed = PostContentParser::parse($content);
                if ($parsed === null) {
                    throw new \RuntimeException('AI did not return valid JSON with a content key.');
                }

                $this->postDao->update($postId, [
                    'status' => 'draft',
                    'content_facebook' => $parsed['content'],
                    'content_linkedin' => $parsed['content'],
                    'ai_model' => $this->settings->get('openrouter_model'),
                    'ai_prompt_snapshot' => json_encode($this->redactMessagesForStorage($messages), JSON_THROW_ON_ERROR),
                    'ai_tool_calls_json' => json_encode($audit, JSON_THROW_ON_ERROR),
                    'generated_at' => gmdate('c'),
                ]);

                return ServiceResult::success('Content generated.', [
                    'post_id' => $postId,
                    'image_prompt' => $parsed['image_prompt'] ?? null,
                ]);
            }

            throw new \RuntimeException('Max agent turns exceeded.');
        } catch (\Throwable $e) {
            $this->postDao->update($postId, [
                'status' => 'draft',
                'ai_error' => $e->getMessage(),
                'ai_tool_calls_json' => json_encode($audit, JSON_THROW_ON_ERROR),
            ]);

            return ServiceResult::failure($e->getMessage(), 'GENERATION_FAILED');
        }
    }

    /**
     * @param array<int, array{url: string, label: null}> $sources
     */
    private function buildUserPrompt(array $profile, array $sources, int $profileId): string
    {
        $lines = ["Product: {$profile['name']}"];
        if (!empty($profile['posting_guidance'])) {
            $lines[] = "Post guidance:\n{$profile['posting_guidance']}";
        }
        $wantsImage = (int) ($profile['generate_post_image'] ?? 0) === 1;
        if ($wantsImage && !empty($profile['image_guidance'])) {
            $lines[] = "Image guidance (for image_prompt field only, not post copy; URLs here are fetched automatically for image generation):\n{$profile['image_guidance']}";
        }
        if ($sources !== []) {
            $lines[] = 'Source URLs parsed from post guidance — use url_sitemap_children to map site structure, then fetch_page (multiple URLs per call), fetch_resource, and fetch_image to research before writing:';
            foreach ($sources as $s) {
                $lines[] = "- {$s['url']}";
            }
        } else {
            $lines[] = 'No HTTPS source URLs were found in post guidance. Include source site URLs there if the post should research live pages.';
        }

        $historyLimit = $this->settings->getInt('openrouter_max_history_posts', 10);
        $history = $this->postDao->findRecentByProfile($profileId, $historyLimit);
        if ($history !== []) {
            $lines[] = 'Recent successful posts (avoid repeating):';
            foreach ($history as $h) {
                $lines[] = '---';
                $lines[] = $this->postText($h);
            }
        }

        $lines[] = 'Write one social media post for Facebook and LinkedIn (max 3000 characters).';
        if ($wantsImage) {
            $lines[] = 'Research the site first, then respond with JSON only: {"content":"...","image_prompt":"..."}';
            $lines[] = 'content: publishable post copy only — no image directions.';
            $lines[] = 'image_prompt: complete art direction for the image model (scene, layout, brand colors with hex codes, devices, UI on screens). Self-contained; image guidance rules are also applied at render time.';
        } else {
            $lines[] = 'Respond with JSON only: {"content":"..."}';
        }

        return implode("\n\n", $lines);
    }

    private function postText(array $post): string
    {
        $facebook = trim((string) ($post['content_facebook'] ?? ''));
        $linkedin = trim((string) ($post['content_linkedin'] ?? ''));
        if ($facebook !== '' && ($facebook === $linkedin || $linkedin === '')) {
            return $facebook;
        }
        if ($linkedin !== '') {
            return $linkedin;
        }

        return '';
    }

    private function postSystemPrompt(): string
    {
        $prompt = trim($this->settings->get('openrouter_post_system_prompt', ''));
        if ($prompt !== '') {
            return $prompt;
        }

        return 'You research sources using the provided tools and write one unified social media post. '
            . 'Start with url_sitemap_children on each source root URL to discover pages, then fetch_page with the most relevant URLs in one call. '
            . 'Apply the post guidance and instructions in the user message. '
            . 'Return only the JSON format requested in the user message.';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function redactMessagesForStorage(array $messages): array
    {
        $redacted = [];
        foreach ($messages as $message) {
            $copy = $message;
            if (isset($copy['content']) && is_array($copy['content'])) {
                $copy['content'] = array_map(function ($part) {
                    if (!is_array($part)) {
                        return $part;
                    }
                    if (($part['type'] ?? '') === 'image_url') {
                        $part['image_url']['url'] = '[omitted image data]';
                    }

                    return $part;
                }, $copy['content']);
            }
            $redacted[] = $copy;
        }

        return $redacted;
    }
}
