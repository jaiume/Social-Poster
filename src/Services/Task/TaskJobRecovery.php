<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\TaskJobDao;
use App\Support\ServiceResult;

class TaskJobRecovery
{
    private const STALE_PENDING_MINUTES = 2;

    public function __construct(
        private readonly TaskJobDao $jobDao,
    ) {
    }

    /**
     * Clear abandoned or dead-worker jobs so the global gate can open again.
     */
    public function releaseStaleJobs(): int
    {
        $released = 0;
        foreach ($this->jobDao->findActive() as $job) {
            if ($this->releaseIfStale((string) $job['id'], $job)) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function cancelJob(string $jobId): array
    {
        $job = $this->jobDao->findById($jobId);
        if ($job === null) {
            return ServiceResult::failure('Task not found.', 'NOT_FOUND');
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['pending', 'running'], true)) {
            return ServiceResult::failure('Only active tasks can be cancelled.', 'INVALID_STATE');
        }

        if ($status === 'running') {
            $workerPid = (int) ($job['pid'] ?? 0);
            if ($workerPid > 0) {
                $this->killProcess($workerPid);
            }
        }

        $steps = $this->jobDao->decodeJsonField($job['steps_json'] ?? null);
        foreach ($steps as $i => $step) {
            if (($step['status'] ?? '') === 'running') {
                $steps[$i]['status'] = 'failed';
            }
        }

        $this->jobDao->update($jobId, [
            'status' => 'failed',
            'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
            'error_message' => 'Cancelled by user.',
            'finished_at' => gmdate('c'),
            'pid' => null,
        ]);

        return ServiceResult::success('Task cancelled.', ['job_id' => $jobId, 'status' => 'failed']);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function releaseIfStale(string $jobId, array $job): bool
    {
        $status = (string) ($job['status'] ?? '');

        if ($status === 'running') {
            $pid = (int) ($job['pid'] ?? 0);
            if ($pid > 0 && $this->isProcessRunning($pid)) {
                return false;
            }

            $this->jobDao->update($jobId, [
                'status' => 'pending',
                'pid' => null,
            ]);

            return true;
        }

        if ($status === 'pending') {
            $createdAt = strtotime((string) ($job['created_at'] ?? ''));
            if ($createdAt > 0 && (time() - $createdAt) < self::STALE_PENDING_MINUTES * 60) {
                return false;
            }

            $this->jobDao->update($jobId, [
                'status' => 'failed',
                'error_message' => 'Task was abandoned before it started.',
                'finished_at' => gmdate('c'),
                'pid' => null,
            ]);

            return true;
        }

        return false;
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

    private function killProcess(int $pid): void
    {
        if ($pid <= 0 || !$this->isProcessRunning($pid)) {
            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(300_000);
            if ($this->isProcessRunning($pid)) {
                @posix_kill($pid, SIGKILL);
            }

            return;
        }

        shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
        usleep(300_000);
        if ($this->isProcessRunning($pid)) {
            shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
}
