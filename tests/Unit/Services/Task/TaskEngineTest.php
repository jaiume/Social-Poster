<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Task;

use App\DAO\TaskJobDao;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\TaskEngine;
use App\Services\Task\TaskJobRecovery;
use App\Services\Task\TaskWorkerService;
use PHPUnit\Framework\TestCase;

class TaskEngineTest extends TestCase
{
    private function engine(
        TaskJobDao $jobDao,
        ?PipelineBuilder $pipeline = null,
        ?TaskWorkerService $worker = null
    ): TaskEngine {
        $recovery = $this->createMock(TaskJobRecovery::class);
        $recovery->method('releaseStaleJobs')->willReturn(0);

        $worker ??= $this->createMock(TaskWorkerService::class);

        return new TaskEngine(
            $jobDao,
            $pipeline ?? $this->createMock(PipelineBuilder::class),
            $recovery,
            $worker
        );
    }

    public function testEnqueueRejectsWhenAnyTaskActive(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('hasAnyActiveJob')->willReturn(true);
        $jobDao->method('findActiveJobId')->willReturn('existing-job');

        $worker = $this->createMock(TaskWorkerService::class);
        $worker->expects($this->never())->method('run');

        $result = $this->engine($jobDao, null, $worker)->enqueue(PipelineBuilder::RECIPE_GENERATE_POST, [
            'product_profile_id' => 1,
            'schedule_date' => '2026-06-19',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('TASK_ALREADY_RUNNING', $result['error']['code']);
    }

    public function testEnqueueDoesNotRunWorker(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('hasAnyActiveJob')->willReturn(false);
        $jobDao->expects($this->once())->method('create')->willReturn('new-job');

        $pipeline = $this->createMock(PipelineBuilder::class);
        $pipeline->method('build')->willReturn([
            ['key' => 'generation.content', 'label' => 'Generating post content', 'status' => 'pending'],
        ]);

        $worker = $this->createMock(TaskWorkerService::class);
        $worker->expects($this->never())->method('run');

        $result = $this->engine($jobDao, $pipeline, $worker)->enqueue(PipelineBuilder::RECIPE_GENERATE_POST, [
            'product_profile_id' => 1,
            'schedule_date' => '2026-06-19',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('new-job', $result['data']['job_id']);
    }

    public function testEnqueueCreatesJobAndReturnsId(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('hasAnyActiveJob')->willReturn(false);
        $jobDao->expects($this->once())->method('create')->willReturn('abc123');

        $pipeline = $this->createMock(PipelineBuilder::class);
        $pipeline->method('build')->willReturn([
            ['key' => 'generation.content', 'label' => 'Generating post content', 'status' => 'pending'],
        ]);

        $result = $this->engine($jobDao, $pipeline)->enqueue(PipelineBuilder::RECIPE_GENERATE_POST, [
            'product_profile_id' => 1,
            'schedule_date' => '2026-06-19',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('abc123', $result['data']['job_id']);
    }

    public function testStartRunsWorkerForPendingJob(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('findById')->willReturnOnConsecutiveCalls(
            [
                'id' => 'job1',
                'status' => 'pending',
                'pid' => null,
            ],
            [
                'id' => 'job1',
                'status' => 'completed',
                'pid' => null,
            ]
        );
        $jobDao->method('findActiveJobId')->willReturn('job1');

        $worker = $this->createMock(TaskWorkerService::class);
        $worker->expects($this->once())->method('run')->with('job1')->willReturn([
            'exit_code' => 0,
            'failed' => false,
            'error_message' => null,
        ]);

        $result = $this->engine($jobDao, null, $worker)->start('job1');

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['data']['status']);
    }

    public function testGetStatusIsReadOnly(): void
    {
        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->expects($this->once())->method('findById')->with('job1')->willReturn([
            'id' => 'job1',
            'recipe' => 'generate_post',
            'status' => 'pending',
            'current_step' => 0,
            'steps_json' => '[]',
            'result_json' => '{}',
            'error_message' => null,
            'post_id' => null,
            'product_profile_id' => 5,
            'started_at' => null,
            'finished_at' => null,
        ]);
        $jobDao->method('decodeJsonField')->willReturn([]);

        $result = $this->engine($jobDao)->getStatus('job1');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['data']['status']);
        $this->assertSame(5, $result['data']['product_profile_id']);
    }
}
