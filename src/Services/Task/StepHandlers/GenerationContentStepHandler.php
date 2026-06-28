<?php

declare(strict_types=1);

namespace App\Services\Task\StepHandlers;

use App\DAO\ProductProfileDao;
use App\DAO\TaskJobDao;
use App\Services\ContentGenerationService;
use App\Services\ImageGenerationService;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\TaskJobContext;

class GenerationContentStepHandler
{
    public function __construct(
        private readonly ContentGenerationService $contentGeneration,
        private readonly ImageGenerationService $imageGeneration,
        private readonly ProductProfileDao $profileDao,
        private readonly TaskJobDao $jobDao,
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
        $payload = $this->context->payload($job);
        $recipe = (string) ($job['recipe'] ?? '');
        $profileId = (int) ($payload['product_profile_id'] ?? $job['product_profile_id'] ?? 0);

        $profile = $this->profileDao->findById($profileId);
        if ($profile === null) {
            return ['success' => false, 'error' => 'Profile not found.'];
        }

        if ($recipe === PipelineBuilder::RECIPE_REGENERATE_POST) {
            $postId = (int) ($payload['post_id'] ?? $job['post_id'] ?? 0);
            if ($postId <= 0) {
                return ['success' => false, 'error' => 'Post ID required for regenerate.'];
            }
            $this->imageGeneration->clearForPost($postId);
            $result = $this->contentGeneration->regenerateContentOnly($postId);
        } else {
            $postId = (int) ($payload['post_id'] ?? $job['post_id'] ?? 0);
            if ($postId <= 0) {
                $postId = $this->contentGeneration->createPostForGeneration($profileId);
            }
            $result = $this->contentGeneration->generateContentOnly($postId, $profileId, $profile);
        }

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message']];
        }

        $data = [
            'post_id' => (int) ($result['data']['post_id'] ?? $postId),
            'image_prompt' => $result['data']['image_prompt'] ?? null,
        ];

        $this->context->mergeResult($jobId, $job, $data);
        $this->jobDao->update($jobId, [
            'post_id' => $data['post_id'],
            'product_profile_id' => $profileId,
        ]);

        return ['success' => true, 'data' => $data];
    }
}
