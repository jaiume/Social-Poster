<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\PostDao;
use App\Support\ImageBytesValidator;
use App\Support\ServiceResult;

class ImageGenerationService
{
    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly AppSettingsService $settings,
        private readonly PostDao $postDao,
        private readonly ImageGuidanceResolver $guidanceResolver
    ) {
    }

    /**
     * Resolve references and write prep artifact to disk.
     */
    public function prepareReferences(array $profile, string $jobId): string
    {
        $imageGuidance = trim((string) ($profile['image_guidance'] ?? ''));
        $resolved = $this->guidanceResolver->resolve($imageGuidance);

        $path = \App\Services\Task\TaskJobContext::prepArtifactPath($jobId);
        file_put_contents($path, json_encode($resolved, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function renderImage(
        int $postId,
        array $profile,
        string $content,
        ?string $imagePrompt,
        string $prepPath
    ): array {
        if ((int) ($profile['generate_post_image'] ?? 0) !== 1) {
            return ['success' => true, 'message' => 'Image generation disabled.'];
        }

        $resolved = $this->loadPrepArtifact($prepPath);
        $prompt = $this->buildRenderPrompt($profile, $content, $imagePrompt, $resolved['page_context'] ?? '');
        if ($prompt === '') {
            $this->postDao->update($postId, [
                'image_error' => 'No image prompt was produced. Regenerate the post.',
            ]);

            return ['success' => false, 'message' => 'No image prompt was produced.'];
        }

        try {
            $imageBytes = $this->openRouter->generateImage(
                $this->renderPrefix(),
                $prompt,
                $resolved['reference_images'] ?? [],
                $postId
            );
            if ($imageBytes === null || ImageBytesValidator::isLikelyBlank($imageBytes)) {
                $this->postDao->update($postId, [
                    'image_error' => 'Image model returned a blank or invalid image. Try regenerating the image.',
                ]);

                return ['success' => false, 'message' => 'Image model returned a blank or invalid image.'];
            }

            $extension = ImageBytesValidator::extensionFor($imageBytes);
            $dir = BASE_DIR . '/var/data/post-images';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->clearImageFiles($postId);
            $relativePath = 'var/data/post-images/' . $postId . '.' . $extension;
            file_put_contents(BASE_DIR . '/' . $relativePath, $imageBytes);
            $this->postDao->update($postId, [
                'image_path' => $relativePath,
                'image_error' => null,
            ]);

            return ['success' => true, 'message' => 'Image generated.'];
        } catch (\Throwable $e) {
            $this->postDao->update($postId, ['image_error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @deprecated Use task engine prep + render steps.
     */
    public function generateForPost(
        int $postId,
        array $profile,
        string $content,
        ?string $imagePrompt = null
    ): bool {
        if ((int) ($profile['generate_post_image'] ?? 0) !== 1) {
            return false;
        }

        $jobId = bin2hex(random_bytes(8));
        $this->prepareReferences($profile, $jobId);
        $relative = \App\Services\Task\TaskJobContext::relativePrepArtifactPath($jobId);
        $result = $this->renderImage($postId, $profile, $content, $imagePrompt, $relative);

        return $result['success'];
    }

    public function clearForPost(int $postId): void
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return;
        }

        $this->clearImageFiles($postId);
        $this->postDao->update($postId, ['image_path' => null, 'image_error' => null]);
    }

    public static function resolveAbsolutePath(?array $post): ?string
    {
        if ($post === null || empty($post['image_path'])) {
            return null;
        }

        $fullPath = BASE_DIR . '/' . ltrim((string) $post['image_path'], '/');
        if (is_file($fullPath)) {
            return $fullPath;
        }

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $alt = BASE_DIR . '/var/data/post-images/' . (int) $post['id'] . '.' . $ext;
            if (is_file($alt)) {
                return $alt;
            }
        }

        return null;
    }

    public static function stripVisualConceptFromContent(string $text): string
    {
        $text = preg_replace('/\n*Visual concept:.*$/is', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{reference_images: array<int, mixed>, page_context: string}
     */
    private function loadPrepArtifact(string $prepPath): array
    {
        $fullPath = str_starts_with($prepPath, 'var/')
            ? BASE_DIR . '/' . $prepPath
            : $prepPath;
        if (!is_file($fullPath)) {
            return ['reference_images' => [], 'page_context' => ''];
        }

        $decoded = json_decode((string) file_get_contents($fullPath), true);

        return is_array($decoded)
            ? [
                'reference_images' => $decoded['reference_images'] ?? [],
                'page_context' => (string) ($decoded['page_context'] ?? ''),
            ]
            : ['reference_images' => [], 'page_context' => ''];
    }

    private function renderPrefix(): string
    {
        $prefix = trim($this->settings->get('openrouter_image_system_prompt', ''));

        return $prefix !== ''
            ? $prefix
            : 'Generate a bright, detailed social media marketing image from this description.';
    }

    private function buildRenderPrompt(
        array $profile,
        string $content,
        ?string $imagePrompt,
        string $pageContext = ''
    ): string {
        $lines = ['Product: ' . ($profile['name'] ?? 'Product')];

        $imageGuidance = trim((string) ($profile['image_guidance'] ?? ''));
        if ($imageGuidance !== '') {
            $lines[] = "Brand and style requirements:\n{$imageGuidance}";
        }

        if (trim($pageContext) !== '') {
            $lines[] = "Referenced web pages:\n{$pageContext}";
        }

        $scene = trim($imagePrompt ?? '');
        if ($scene === '') {
            $scene = $this->extractVisualConcept($content) ?? '';
        }
        if ($scene !== '') {
            $lines[] = "Scene:\n{$scene}";
        }

        return trim(implode("\n\n", $lines));
    }

    private function extractVisualConcept(string $content): ?string
    {
        if (preg_match('/Visual concept:\s*(.+)$/is', $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function clearImageFiles(int $postId): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = BASE_DIR . '/var/data/post-images/' . $postId . '.' . $ext;
            if (is_file($path)) {
                unlink($path);
            }
        }

        $post = $this->postDao->findById($postId);
        if ($post !== null && !empty($post['image_path'])) {
            $legacy = BASE_DIR . '/' . ltrim((string) $post['image_path'], '/');
            if (is_file($legacy)) {
                unlink($legacy);
            }
        }
    }
}
