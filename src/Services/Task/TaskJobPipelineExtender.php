<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\TaskJobDao;

class TaskJobPipelineExtender
{
    public function __construct(
        private readonly TaskJobDao $jobDao,
        private readonly PipelineBuilder $pipelineBuilder
    ) {
    }

    public function appendPublishSteps(string $jobId): void
    {
        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return;
        }

        $result = $this->jobDao->decodeJsonField($job['result_json'] ?? null);
        $postId = (int) ($result['post_id'] ?? $job['post_id'] ?? 0);
        if ($postId <= 0) {
            return;
        }

        $payload = $this->jobDao->decodeJsonField($job['payload_json'] ?? null);
        $payload['post_id'] = $postId;
        $publishSteps = $this->pipelineBuilder->build(PipelineBuilder::RECIPE_PUBLISH_POST, $payload);
        if ($publishSteps === []) {
            return;
        }

        $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
        foreach ($steps as $step) {
            if (PipelineBuilder::isPublishingStep((string) ($step['key'] ?? ''))) {
                return;
            }
        }

        $merged = array_merge($steps, $publishSteps);
        $this->jobDao->update($jobId, [
            'steps_json' => json_encode($merged, JSON_THROW_ON_ERROR),
            'post_id' => $postId,
        ]);
    }
}
