<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\TaskJobDao;
use App\Support\ServiceResult;

class TaskEngine
{
    public function __construct(
        private readonly TaskJobDao $jobDao,
        private readonly PipelineBuilder $pipelineBuilder,
        private readonly TaskJobRecovery $recovery,
        private readonly TaskWorkerService $worker
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function enqueue(string $recipe, array $payload): array
    {
        $this->recovery->releaseStaleJobs();
        $profileId = (int) ($payload['product_profile_id'] ?? 0);
        $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : null;

        $dedup = $this->validateEnqueue($profileId);
        if ($dedup !== null) {
            return $dedup;
        }

        try {
            $steps = $this->pipelineBuilder->build($recipe, $payload);
        } catch (\Throwable $e) {
            return ServiceResult::failure($e->getMessage(), 'VALIDATION_ERROR');
        }

        if ($steps === []) {
            return ServiceResult::failure('No steps to run for this recipe.', 'VALIDATION_ERROR');
        }

        $seedResult = $payload['seed_result'] ?? [];
        if (!is_array($seedResult)) {
            $seedResult = [];
        }
        $payloadForStorage = $payload;
        unset($payloadForStorage['seed_result']);

        $initialResult = $seedResult;
        if ($postId !== null && $postId > 0 && !isset($initialResult['post_id'])) {
            $initialResult['post_id'] = $postId;
        }

        $jobId = $this->jobDao->create([
            'recipe' => $recipe,
            'payload_json' => json_encode($payloadForStorage, JSON_THROW_ON_ERROR),
            'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
            'result_json' => json_encode($initialResult, JSON_THROW_ON_ERROR),
            'product_profile_id' => $profileId > 0 ? $profileId : null,
            'post_id' => $postId,
        ]);

        return ServiceResult::success('Task enqueued.', ['job_id' => $jobId]);
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function start(string $jobId): array
    {
        $this->recovery->releaseStaleJobs();

        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return ServiceResult::failure('Task not found.', 'NOT_FOUND');
        }

        $status = (string) ($job['status'] ?? '');

        if ($status === 'completed') {
            return ServiceResult::success('Task already completed.', [
                'job_id' => $jobId,
                'status' => $status,
                'started' => false,
            ]);
        }

        if ($status === 'running') {
            if ($this->isProcessRunning((int) ($job['pid'] ?? 0))) {
                return ServiceResult::success('Task already running.', [
                    'job_id' => $jobId,
                    'status' => $status,
                    'started' => false,
                ]);
            }
        } elseif ($status !== 'pending') {
            if ($status === 'failed') {
                return ServiceResult::success('Task failed.', [
                    'job_id' => $jobId,
                    'status' => $status,
                    'started' => false,
                ]);
            }

            return ServiceResult::failure('Task cannot be started.', 'INVALID_STATE');
        }

        $activeId = $this->jobDao->findActiveJobId();
        if ($activeId !== null && $activeId !== $jobId) {
            return ServiceResult::failure(
                'A task is already in progress.',
                'TASK_ALREADY_RUNNING',
                ['active_job_id' => $activeId]
            );
        }

        $run = $this->worker->run($jobId);
        $job = $this->jobDao->findById($jobId);
        $finalStatus = (string) ($job['status'] ?? 'failed');

        if ($run['failed'] || $finalStatus === 'failed') {
            return ServiceResult::failure(
                $run['error_message'] ?? (string) ($job['error_message'] ?? 'Task failed.'),
                'TASK_FAILED',
                ['job_id' => $jobId, 'status' => $finalStatus]
            );
        }

        return ServiceResult::success('Task completed.', [
            'job_id' => $jobId,
            'status' => $finalStatus,
            'started' => true,
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}|null
     */
    private function validateEnqueue(int $profileId): ?array
    {
        if ($this->jobDao->hasAnyActiveJob()) {
            $activeId = $this->jobDao->findActiveJobId();
            $message = 'A task is already in progress.';

            return ServiceResult::failure(
                $message,
                'TASK_ALREADY_RUNNING',
                ['active_job_id' => $activeId]
            );
        }

        return null;
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function getStatus(string $jobId): array
    {
        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return ServiceResult::failure('Task not found.', 'NOT_FOUND');
        }

        $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
        $result = $this->jobDao->decodeJsonField($job['result_json'] ?? null);

        return ServiceResult::success('OK', [
            'job_id' => $jobId,
            'recipe' => $job['recipe'],
            'status' => $job['status'],
            'current_step' => (int) ($job['current_step'] ?? 0),
            'steps' => $steps,
            'error_message' => $job['error_message'],
            'post_id' => $result['post_id'] ?? $job['post_id'],
            'product_profile_id' => $job['product_profile_id'] !== null ? (int) $job['product_profile_id'] : null,
            'result' => $result,
            'started_at' => $job['started_at'],
            'finished_at' => $job['finished_at'],
        ]);
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        $status = shell_exec('ps -p ' . $pid . ' -o pid=');

        return trim((string) $status) !== '';
    }
}
