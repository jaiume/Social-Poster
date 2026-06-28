<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\TaskJobDao;

final class TaskJobContext
{
    public function __construct(
        private readonly TaskJobDao $jobDao
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function result(array $job): array
    {
        return $this->jobDao->decodeJsonField($job['result_json'] ?? null);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function payload(array $job): array
    {
        return $this->jobDao->decodeJsonField($job['payload_json'] ?? null);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $data
     */
    public function mergeResult(string $jobId, array $job, array $data): void
    {
        $merged = array_merge($this->result($job), $data);
        $this->jobDao->update($jobId, [
            'result_json' => json_encode($merged, JSON_THROW_ON_ERROR),
        ]);
    }

    public static function prepArtifactPath(string $jobId): string
    {
        $dir = BASE_DIR . '/var/data/task-jobs/' . preg_replace('/[^a-f0-9]/', '', $jobId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/prep.json';
    }

    public static function relativePrepArtifactPath(string $jobId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', $jobId);

        return 'var/data/task-jobs/' . $safe . '/prep.json';
    }
}
