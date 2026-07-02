<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Task;

use App\DAO\TaskJobDao;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\StepHandlerRegistry;
use App\Services\Task\TaskWorkerService;
use PHPUnit\Framework\TestCase;

class TaskWorkerServiceTest extends TestCase
{
    public function testRunResumesFromCurrentStep(): void
    {
        $steps = [
            ['key' => 'generation.content', 'label' => 'Content', 'status' => 'completed'],
            ['key' => 'generation.finalize', 'label' => 'Finalize', 'status' => 'pending'],
        ];

        $job = [
            'id' => 'job1',
            'recipe' => PipelineBuilder::RECIPE_GENERATE_POST,
            'status' => 'pending',
            'current_step' => 1,
            'steps_json' => json_encode($steps, JSON_THROW_ON_ERROR),
            'result_json' => json_encode(['post_id' => 10], JSON_THROW_ON_ERROR),
            'post_id' => 10,
        ];

        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('findById')->willReturnCallback(function () use (&$job, $steps) {
            $job['steps_json'] = json_encode($steps, JSON_THROW_ON_ERROR);

            return $job;
        });
        $jobDao->method('decodeJsonField')->willReturnCallback(function (?string $json) {
            $decoded = json_decode((string) $json, true);

            return is_array($decoded) ? $decoded : [];
        });
        $jobDao->method('update')->willReturnCallback(function (string $id, array $data) use (&$job, &$steps): void {
            if (isset($data['current_step'])) {
                $job['current_step'] = (int) $data['current_step'];
            }
            if (isset($data['steps_json'])) {
                $steps = json_decode((string) $data['steps_json'], true) ?: $steps;
            }
            if (isset($data['status'])) {
                $job['status'] = (string) $data['status'];
            }
        });
        $jobDao->method('tryClaimJob')->willReturn(true);

        $handledKeys = [];
        $registry = $this->createMock(StepHandlerRegistry::class);
        $registry->method('handle')->willReturnCallback(function ($jobId, $jobRow, $step) use (&$handledKeys) {
            $handledKeys[] = $step['key'];

            return ['success' => true];
        });

        $worker = new TaskWorkerService($jobDao, $registry);

        $result = $worker->run('job1');

        $this->assertFalse($result['failed']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(['generation.finalize'], $handledKeys);
        $this->assertSame('completed', $job['status']);
    }
}
