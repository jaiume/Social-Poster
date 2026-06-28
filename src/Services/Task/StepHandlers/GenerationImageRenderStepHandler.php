<?php

declare(strict_types=1);

namespace App\Services\Task\StepHandlers;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;
use App\Services\ImageGenerationService;
use App\Services\Task\TaskJobContext;

class GenerationImageRenderStepHandler
{
    public function __construct(
        private readonly ImageGenerationService $imageGeneration,
        private readonly PostDao $postDao,
        private readonly ProductProfileDao $profileDao,
        private readonly TaskJobContext $context
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $step
     * @return array{success: bool, error?: string, data?: array<string, mixed>}
     */
    public function handle(string $jobId, array $job, array $step): array
    {
        $result = $this->context->result($job);
        $postId = (int) ($result['post_id'] ?? $job['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'Post ID missing for image render.'];
        }

        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ['success' => false, 'error' => 'Post not found.'];
        }

        $profileId = (int) ($job['product_profile_id'] ?? $post['product_profile_id']);
        $profile = $this->profileDao->findById($profileId);
        if ($profile === null) {
            return ['success' => false, 'error' => 'Profile not found.'];
        }

        $content = trim((string) ($post['content_facebook'] ?? $post['content_linkedin'] ?? ''));
        $imagePrompt = isset($result['image_prompt']) ? (string) $result['image_prompt'] : null;
        $prepPath = (string) ($result['prep_artifact_path'] ?? TaskJobContext::relativePrepArtifactPath($jobId));

        $renderResult = $this->imageGeneration->renderImage(
            $postId,
            $profile,
            $content,
            $imagePrompt,
            $prepPath
        );

        if (!$renderResult['success']) {
            $warnings = $result['warnings'] ?? [];
            if (!is_array($warnings)) {
                $warnings = [];
            }
            $warnings[] = $renderResult['message'] ?? 'Image generation failed.';
            $this->context->mergeResult($jobId, $job, ['warnings' => $warnings]);

            return ['success' => true, 'data' => ['image_error' => $renderResult['message'] ?? 'Image failed.']];
        }

        return ['success' => true];
    }
}
