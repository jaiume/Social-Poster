<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\TaskJobDao;
use App\Services\PostingOrchestratorService;

class TaskWorkerService
{
    public function __construct(
        private readonly TaskJobDao $jobDao,
        private readonly StepHandlerRegistry $registry,
        private readonly PostingOrchestratorService $orchestrator,
        private readonly TaskJobPipelineExtender $pipelineExtender
    ) {
    }

    /**
     * Run all remaining steps for a job starting at current_step.
     *
     * @return array{exit_code: int, failed: bool, error_message: ?string}
     */
    public function run(string $jobId): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $lockFp = $this->acquireLock($jobId);
        if ($lockFp === null) {
            return ['exit_code' => 0, 'failed' => false, 'error_message' => null];
        }

        try {
            return $this->runLocked($jobId);
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * @return array{exit_code: int, failed: bool, error_message: ?string}
     */
    private function runLocked(string $jobId): array
    {
        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return ['exit_code' => 1, 'failed' => true, 'error_message' => 'Job not found.'];
        }

        if (($job['status'] ?? '') === 'completed') {
            return ['exit_code' => 0, 'failed' => false, 'error_message' => null];
        }

        if (($job['status'] ?? '') === 'pending') {
            if (!$this->jobDao->tryClaimJob($jobId)) {
                return ['exit_code' => 0, 'failed' => false, 'error_message' => null];
            }
        } elseif (($job['status'] ?? '') === 'running') {
            $this->jobDao->update($jobId, ['pid' => getmypid()]);
        } else {
            return ['exit_code' => 0, 'failed' => false, 'error_message' => null];
        }

        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return ['exit_code' => 1, 'failed' => true, 'error_message' => 'Job not found.'];
        }

        $currentStep = (int) ($job['current_step'] ?? 0);
        $failed = false;
        $errorMessage = null;

        try {
            while (true) {
                $job = $this->jobDao->findById($jobId);
                if ($job === null) {
                    break;
                }

                $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
                if ($currentStep >= count($steps)) {
                    break;
                }

                $step = $steps[$currentStep];
                $stepKey = (string) ($step['key'] ?? '');

                $steps[$currentStep]['status'] = 'running';
                $this->jobDao->update($jobId, [
                    'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
                ]);

                $result = $this->registry->handle($jobId, $job, $step);
                $job = $this->jobDao->findById($jobId);
                if ($job === null) {
                    break;
                }
                $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);

                if (!$result['success']) {
                    $steps[$currentStep]['status'] = 'failed';
                    $this->jobDao->update($jobId, [
                        'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
                        'status' => 'failed',
                        'error_message' => $result['error'] ?? 'Step failed.',
                        'finished_at' => gmdate('c'),
                        'pid' => null,
                    ]);
                    $failed = true;
                    $errorMessage = $result['error'] ?? 'Step failed.';
                    break;
                }

                $steps[$currentStep]['status'] = 'completed';
                $currentStep++;
                $this->jobDao->update($jobId, [
                    'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
                    'current_step' => $currentStep,
                ]);

                if (
                    $stepKey === 'generation.finalize'
                    && ($job['recipe'] ?? '') === PipelineBuilder::RECIPE_PROFILE_DAILY_POST
                ) {
                    $this->pipelineExtender->appendPublishSteps($jobId);
                    $job = $this->jobDao->findById($jobId);
                    if ($job === null) {
                        break;
                    }
                    $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
                }
            }

            if (!$failed) {
                $job = $this->jobDao->findById($jobId);
                $steps = $job !== null ? $this->jobDao->decodeJsonField($job['steps_json'] ?? null) : [];
                $hasPublishing = false;
                foreach ($steps as $s) {
                    if (PipelineBuilder::isPublishingStep((string) ($s['key'] ?? ''))) {
                        $hasPublishing = true;
                        break;
                    }
                }

                if ($hasPublishing && $job !== null) {
                    $result = $this->jobDao->decodeJsonField($job['result_json'] ?? null);
                    $postId = (int) ($result['post_id'] ?? $job['post_id'] ?? 0);
                    if ($postId > 0) {
                        $complete = $this->orchestrator->completePublishing($postId);
                        if (!($complete['data']['any_success'] ?? false)) {
                            $failed = true;
                            $errorMessage = 'One or more publish steps failed.';
                        }
                    }
                }

                $this->jobDao->update($jobId, [
                    'status' => $failed ? 'failed' : 'completed',
                    'error_message' => $errorMessage,
                    'finished_at' => gmdate('c'),
                    'pid' => null,
                ]);
            } else {
                $job = $this->jobDao->findById($jobId);
                if ($job !== null) {
                    $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
                    foreach ($steps as $s) {
                        if (PipelineBuilder::isPublishingStep((string) ($s['key'] ?? ''))) {
                            $result = $this->jobDao->decodeJsonField($job['result_json'] ?? null);
                            $postId = (int) ($result['post_id'] ?? $job['post_id'] ?? 0);
                            if ($postId > 0) {
                                $this->orchestrator->completePublishing($postId);
                            }
                            break;
                        }
                    }
                }
                $this->jobDao->update($jobId, ['pid' => null]);
            }
        } catch (\Throwable $e) {
            $this->jobDao->update($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => gmdate('c'),
                'pid' => null,
            ]);

            return ['exit_code' => 1, 'failed' => true, 'error_message' => $e->getMessage()];
        }

        return [
            'exit_code' => $failed ? 1 : 0,
            'failed' => $failed,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @return resource|null
     */
    private function acquireLock(string $jobId)
    {
        $lockDir = BASE_DIR . '/var/data/task-jobs';
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $safeId = preg_replace('/[^a-f0-9]/', '', $jobId);
        $lockPath = $lockDir . '/' . $safeId . '.lock';
        $lockFp = fopen($lockPath, 'c+');
        if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockFp)) {
                fclose($lockFp);
            }

            return null;
        }

        return $lockFp;
    }
}
