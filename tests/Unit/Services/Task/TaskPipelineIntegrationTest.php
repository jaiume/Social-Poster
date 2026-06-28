<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Task;

use App\DAO\PostDao;
use App\DAO\PostPublicationDao;
use App\DAO\ProductProfileDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\PublicationAttemptStateDao;
use App\DAO\SessionAccountDao;
use App\DAO\TaskJobDao;
use App\Services\AppSettingsService;
use App\Services\PosterAutomationService;
use App\Services\PostingOrchestratorService;
use App\Services\PublishPlanBuilder;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\StepHandlers\PublishingStepHandler;
use App\Services\Task\TaskJobContext;
use PHPUnit\Framework\TestCase;

class TaskPipelineIntegrationTest extends TestCase
{
    public function testPublishStepHandlerRunsPosterForPrimaryStep(): void
    {
        $post = [
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'approved',
            'content_facebook' => 'Hello',
            'content_linkedin' => 'Hello',
        ];

        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn($post);

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->with(1)->willReturn([
            'id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'root',
            'browser_session_id' => 7,
        ]);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('create')->willReturn(100);
        $publicationDao->expects($this->atLeastOnce())->method('update');

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->expects($this->once())
            ->method('publishFacebookPrimary')
            ->willReturn(['success' => true, 'verified' => true, 'postUrl' => 'https://facebook.com/posts/1']);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturnCallback(
            fn (string $key, int $default = 0) => match ($key) {
                'browser_repost_delay_ms' => 0,
                default => $default,
            }
        );

        $planBuilder = $this->createMock(PublishPlanBuilder::class);
        $planBuilder->method('build')->willReturn([
            ['label' => 'Posting to Facebook', 'platform' => 'facebook', 'action' => 'post', 'session_account_id' => 1],
        ]);

        $orchestrator = new PostingOrchestratorService(
            $this->createMock(ProductProfileDao::class),
            $accountDao,
            $this->createMock(ProfilePostingAccountDao::class),
            $postDao,
            $publicationDao,
            $this->createMock(PublicationAttemptStateDao::class),
            $settings,
            $poster,
            $planBuilder,
            $this->createMock(PipelineBuilder::class),
            $this->createMock(TaskJobDao::class)
        );

        $jobDao = $this->createMock(TaskJobDao::class);
        $jobDao->method('decodeJsonField')->willReturnCallback(function (?string $json) {
            $decoded = json_decode((string) $json, true);

            return is_array($decoded) ? $decoded : [];
        });

        $handler = new PublishingStepHandler(
            $orchestrator,
            $postDao,
            $settings,
            new TaskJobContext($jobDao)
        );

        $job = [
            'payload_json' => json_encode(['post_id' => 10, 'account_ids' => [1]], JSON_THROW_ON_ERROR),
            'result_json' => json_encode(['post_id' => 10], JSON_THROW_ON_ERROR),
            'post_id' => 10,
        ];

        $step = [
            'key' => 'publishing.facebook.post',
            'label' => 'Posting to Facebook',
            'status' => 'pending',
            'meta' => [
                'platform' => 'facebook',
                'action' => 'post',
                'session_account_id' => 1,
            ],
        ];

        $result = $handler->handle('job1', $job, $step);

        $this->assertTrue($result['success']);
    }
}
