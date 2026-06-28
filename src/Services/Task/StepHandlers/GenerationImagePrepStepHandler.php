<?php

declare(strict_types=1);

namespace App\Services\Task\StepHandlers;

use App\DAO\ProductProfileDao;
use App\Services\ImageGenerationService;
use App\Services\Task\TaskJobContext;

class GenerationImagePrepStepHandler
{
    public function __construct(
        private readonly ImageGenerationService $imageGeneration,
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
            return ['success' => false, 'error' => 'Post ID missing for image prep.'];
        }

        $profileId = (int) ($job['product_profile_id'] ?? $this->context->payload($job)['product_profile_id'] ?? 0);
        $profile = $this->profileDao->findById($profileId);
        if ($profile === null) {
            return ['success' => false, 'error' => 'Profile not found.'];
        }

        $prepPath = $this->imageGeneration->prepareReferences($profile, $jobId);
        $relative = TaskJobContext::relativePrepArtifactPath($jobId);
        $data = ['prep_artifact_path' => $relative];
        $this->context->mergeResult($jobId, $job, $data);

        return ['success' => true, 'data' => $data];
    }
}
