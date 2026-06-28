<?php

declare(strict_types=1);

namespace App\Services\Task\StepHandlers;

use App\DAO\PostDao;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\TaskJobContext;

class GenerationFinalizeStepHandler
{
    public function __construct(
        private readonly PostDao $postDao,
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
            return ['success' => false, 'error' => 'Post ID missing for finalize.'];
        }

        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ['success' => false, 'error' => 'Post not found.'];
        }

        if (($post['status'] ?? '') === 'failed') {
            return ['success' => false, 'error' => (string) ($post['ai_error'] ?? 'Content generation failed.')];
        }

        $recipe = (string) ($job['recipe'] ?? '');
        $status = (string) ($post['status'] ?? 'draft');
        if ($recipe !== PipelineBuilder::RECIPE_REGENERATE_IMAGE) {
            $this->postDao->update($postId, ['status' => 'draft']);
            $status = 'draft';
        }

        return ['success' => true, 'data' => ['post_id' => $postId, 'status' => $status]];
    }
}
