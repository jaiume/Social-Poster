<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\PostDao;
use App\DAO\PostPublicationDao;
use App\DAO\ProductProfileDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\PublicationAttemptStateDao;
use App\DAO\SessionAccountDao;
use App\DAO\TaskJobDao;
use App\Services\AppSettingsService;
use App\Services\PosterAutomationService;
use App\Services\PostingOrchestratorService;
use App\Services\PublishPlanBuilder;
use App\Services\Task\PipelineBuilder;
use PHPUnit\Framework\TestCase;

class PostingOrchestratorServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function orchestrator(array $overrides = []): PostingOrchestratorService
    {
        return new PostingOrchestratorService(
            $overrides['profileDao'] ?? $this->createMock(ProductProfileDao::class),
            $overrides['accountDao'] ?? $this->createMock(SessionAccountDao::class),
            $overrides['postingDao'] ?? $this->createMock(ProfilePostingAccountDao::class),
            $overrides['postDao'] ?? $this->createMock(PostDao::class),
            $overrides['publicationDao'] ?? $this->createMock(PostPublicationDao::class),
            $overrides['attemptStateDao'] ?? $this->createMock(PublicationAttemptStateDao::class),
            $overrides['settings'] ?? $this->createMock(AppSettingsService::class),
            $overrides['poster'] ?? $this->createMock(PosterAutomationService::class),
            $overrides['planBuilder'] ?? $this->createMock(PublishPlanBuilder::class),
            $overrides['pipelineBuilder'] ?? $this->createMock(PipelineBuilder::class),
            $overrides['taskJobDao'] ?? $this->createMock(TaskJobDao::class)
        );
    }

    public function testBuildPublishPlanOrdersPrimaryBeforeReposts(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
        ]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 1, 'platform' => 'facebook'],
                'linkedin' => ['session_account_id' => 3, 'platform' => 'linkedin'],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['session_account_id' => 2, 'platform' => 'facebook'],
            ['session_account_id' => 4, 'platform' => 'linkedin'],
        ]);

        $orchestrator = $this->orchestrator([
            'postDao' => $postDao,
            'postingDao' => $postingDao,
            'planBuilder' => new PublishPlanBuilder($postDao, $postingDao, $repostDao),
        ]);

        $plan = $orchestrator->buildPublishPlan(10);

        $this->assertSame(
            [
                'Posting to Facebook',
                'Posting to LinkedIn',
                'Reposting on Facebook',
                'Reposting on LinkedIn',
            ],
            array_column($plan, 'label')
        );
    }

    public function testBuildPublishPlanFiltersSelectedAccounts(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
        ]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 1, 'platform' => 'facebook'],
                'linkedin' => ['session_account_id' => 3, 'platform' => 'linkedin'],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([]);

        $orchestrator = $this->orchestrator([
            'postDao' => $postDao,
            'postingDao' => $postingDao,
            'planBuilder' => new PublishPlanBuilder($postDao, $postingDao, $repostDao),
        ]);

        $plan = $orchestrator->buildPublishPlan(10, ['account_ids' => [3]]);

        $this->assertCount(1, $plan);
        $this->assertSame(3, $plan[0]['session_account_id']);
    }

    public function testBeginPublishingRequiresApprovedStatus(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'draft',
            'content_facebook' => 'Hello',
        ]);

        $orchestrator = $this->orchestrator(['postDao' => $postDao]);

        $result = $orchestrator->beginPublishing(10);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_STATE', $result['error']['code']);
    }

    public function testBeginPublishingAllowsPostedStatusWithAllMode(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_facebook' => 'Hello',
            'content_linkedin' => 'Hello',
        ]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 1, 'platform' => 'facebook'],
                'linkedin' => ['session_account_id' => 3, 'platform' => 'linkedin'],
                default => null,
            }
        );

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('findPrimarySuccess')->willReturn(['id' => 99, 'external_post_url' => 'https://example.com/post']);

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['session_account_id' => 2, 'platform' => 'facebook'],
        ]);

        $orchestrator = $this->orchestrator([
            'postDao' => $postDao,
            'postingDao' => $postingDao,
            'publicationDao' => $publicationDao,
            'planBuilder' => new PublishPlanBuilder($postDao, $postingDao, $repostDao),
        ]);

        $result = $orchestrator->beginPublishing(10, [
            'publish_mode' => 'all',
            'allow_republish' => false,
            'publish_batch_id' => 'batch1',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testBeginPublishingRejectsPostedStatusForPostOnlyMode(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_facebook' => 'Hello',
            'content_linkedin' => 'Hello',
        ]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 1, 'platform' => 'facebook'],
                'linkedin' => null,
                default => null,
            }
        );

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('findPrimarySuccess')->willReturn(['id' => 99, 'external_post_url' => 'https://example.com/post']);

        $orchestrator = $this->orchestrator([
            'postDao' => $postDao,
            'postingDao' => $postingDao,
            'publicationDao' => $publicationDao,
        ]);

        $result = $orchestrator->beginPublishing(10, [
            'publish_mode' => 'post',
            'allow_republish' => false,
            'publish_batch_id' => 'batch1',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_STATE', $result['error']['code']);
    }

    public function testExecutePublishStepByAccountSkipsAlreadyPublishedAccount(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'approved',
            'content_facebook' => 'Hello',
            'content_linkedin' => 'Hello',
        ]);

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->with(1)->willReturn([
            'id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'browser_session_id' => 7,
            'sub_page_id' => '123',
        ]);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->with(10, 1)->willReturn(true);

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->expects($this->never())->method('publishFacebookPrimary');

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'poster' => $poster,
        ]);

        $result = $orchestrator->executePublishStepByAccount(10, 1, 'post', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('skipped', $result['data']['step_status']);
    }

    public function testPublishPrimaryCallsPosterAutomation(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'approved',
            'content_facebook' => 'Hello',
        ]);

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->with(1)->willReturn([
            'id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'browser_session_id' => 7,
            'sub_page_id' => '123',
        ]);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('create')->willReturn(100);
        $publicationDao->expects($this->atLeastOnce())->method('update');

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->expects($this->once())
            ->method('publishFacebookPrimary')
            ->with(7, $this->callback(fn (array $payload) => ($payload['accountKind'] ?? '') === 'sub'))
            ->willReturn(['success' => true, 'verified' => true, 'postUrl' => 'https://facebook.com/posts/1']);

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext(['publish_batch_id' => 'batch1']);

        $result = $orchestrator->executePublishStepByAccount(10, 1, 'post', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['data']['step_status']);
    }
}
