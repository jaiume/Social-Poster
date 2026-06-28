<?php

declare(strict_types=1);

namespace App\Services\Task\StepHandlers;

use App\DAO\PostDao;
use App\Services\AppSettingsService;
use App\Services\PostingOrchestratorService;
use App\Services\Task\TaskJobContext;
use App\Support\PlaywrightLock;

class PublishingStepHandler
{
    public function __construct(
        private readonly PostingOrchestratorService $orchestrator,
        private readonly PostDao $postDao,
        private readonly AppSettingsService $settings,
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
        $payload = $this->context->payload($job);
        $postId = (int) ($result['post_id'] ?? $job['post_id'] ?? $payload['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'Post ID missing for publishing.'];
        }

        $publishContext = [
            'account_ids' => $payload['account_ids'] ?? null,
            'allow_republish' => (bool) ($payload['allow_republish'] ?? false),
            'publish_mode' => (string) ($payload['publish_mode'] ?? 'all'),
            'publish_batch_id' => $result['publish_batch_id'] ?? $payload['publish_batch_id'] ?? null,
        ];

        $this->ensurePublishPhaseStarted($jobId, $job, $postId, $publishContext);

        $meta = $step['meta'] ?? [];
        $accountId = (int) ($meta['session_account_id'] ?? 0);
        $action = (string) ($meta['action'] ?? '');
        $platform = (string) ($meta['platform'] ?? '');

        if ($accountId <= 0 || $action === '' || $platform === '') {
            return ['success' => false, 'error' => 'Invalid publish step metadata.'];
        }

        PlaywrightLock::acquire();
        try {
            $stepResult = $this->orchestrator->executePublishStepByAccount($postId, $accountId, $action, $platform);
        } finally {
            PlaywrightLock::release();
        }

        if (!$stepResult['success']) {
            return ['success' => false, 'error' => $stepResult['message']];
        }

        $stepStatus = (string) ($stepResult['data']['step_status'] ?? 'failed');
        if ($stepStatus === 'failed') {
            return ['success' => false, 'error' => (string) ($stepResult['data']['error'] ?? 'Publish step failed.')];
        }

        return ['success' => true, 'data' => $stepResult['data'] ?? []];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $publishContext
     */
    private function ensurePublishPhaseStarted(string $jobId, array $job, int $postId, array $publishContext): void
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            throw new \RuntimeException('Post not found.');
        }

        if (!empty($publishContext['publish_batch_id'])) {
            $this->orchestrator->setPublishContext($publishContext);

            return;
        }

        if ($this->orchestrator->buildPublishPlan($postId, $publishContext) === []) {
            throw new \RuntimeException('No publish accounts configured.');
        }

        $begin = $this->orchestrator->beginPublishing($postId, $publishContext);
        if (!$begin['success']) {
            throw new \RuntimeException($begin['message']);
        }

        $batchId = (string) ($begin['data']['publish_batch_id'] ?? '');
        if ($batchId !== '') {
            $this->context->mergeResult($jobId, $job, ['publish_batch_id' => $batchId]);
            $publishContext['publish_batch_id'] = $batchId;
            $this->orchestrator->setPublishContext($publishContext);
        }
    }
}
