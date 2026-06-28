<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Task;

use App\DAO\TaskJobDao;
use App\Services\Task\TaskJobRecovery;
use PHPUnit\Framework\TestCase;

class TaskJobRecoveryTest extends TestCase
{
    public function testReleaseStalePendingJobMarksFailed(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('findActive')->willReturn([
            [
                'id' => 'stale-pending',
                'status' => 'pending',
                'created_at' => gmdate('Y-m-d H:i:s', time() - 300),
            ],
        ]);
        $jobDao->expects($this->once())->method('update')->with(
            'stale-pending',
            $this->callback(function (array $data): bool {
                return ($data['status'] ?? '') === 'failed'
                    && str_contains((string) ($data['error_message'] ?? ''), 'abandoned');
            })
        );

        $recovery = new TaskJobRecovery($jobDao);
        $this->assertSame(1, $recovery->releaseStaleJobs());
    }

    public function testReleaseRunningJobWithDeadWorkerResetsToPending(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('findActive')->willReturn([
            [
                'id' => 'dead-run',
                'status' => 'running',
                'pid' => 999999999,
            ],
        ]);
        $jobDao->expects($this->once())->method('update')->with(
            'dead-run',
            $this->equalTo(['status' => 'pending', 'pid' => null])
        );

        $recovery = new TaskJobRecovery($jobDao);
        $this->assertSame(1, $recovery->releaseStaleJobs());
    }

    public function testCancelJobMarksFailed(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('findById')->willReturn([
            'id' => 'job1',
            'status' => 'pending',
            'steps_json' => json_encode([
                ['key' => 'generation.content', 'label' => 'Content', 'status' => 'running'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $jobDao->method('decodeJsonField')->willReturnCallback(function (?string $json) {
            $decoded = json_decode((string) $json, true);

            return is_array($decoded) ? $decoded : [];
        });
        $jobDao->expects($this->once())->method('update')->with(
            'job1',
            $this->callback(function (array $data): bool {
                return ($data['status'] ?? '') === 'failed'
                    && ($data['error_message'] ?? '') === 'Cancelled by user.';
            })
        );

        $recovery = new TaskJobRecovery($jobDao);
        $result = $recovery->cancelJob('job1');

        $this->assertTrue($result['success']);
    }
}
