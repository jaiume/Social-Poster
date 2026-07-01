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

    public function testPublishPrimaryRecoversPermalinkWithBackoffWhenNotCapturedAtPublishTime(): void
    {
        $post = [
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'approved',
            'content_facebook' => 'Hello',
        ];

        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn($post);

        $account = [
            'id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'browser_session_id' => 7,
            'sub_page_id' => '123',
            'display_name' => 'EntryZen Facebook',
        ];

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->with(1)->willReturn($account);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('create')->willReturn(200);
        // Not yet marked success at recovery time, so resolvePrimaryUrlWithBackoff
        // should fall back to the pubId/empty-url row passed in explicitly.
        $publicationDao->method('findPrimarySuccess')->willReturn(null);
        $publicationDao->expects($this->atLeastOnce())->method('update');

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturn([
            'session_account_id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'sub_page_id' => '123',
            'display_name' => 'EntryZen Facebook',
        ]);

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->expects($this->once())
            ->method('publishFacebookPrimary')
            ->willReturn(['success' => true, 'verified' => true, 'postUrl' => '']);
        // First recovery attempt fails (post not yet in the feed), second succeeds -
        // proving the primary-post path now retries with backoff instead of giving
        // up after a single immediate attempt.
        $poster->method('resolveFacebookPrimaryUrl')->willReturnOnConsecutiveCalls(
            ['success' => false, 'error' => 'not found'],
            ['success' => true, 'postUrl' => 'https://facebook.com/posts/1'],
        );

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'postingDao' => $postingDao,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext(['publish_batch_id' => 'batch1']);

        $result = $orchestrator->executePublishStepByAccount(10, 1, 'post', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['data']['step_status']);
    }

    public function testRepostRecoversPrimaryPostUrlWithBackoffWhenEmpty(): void
    {
        $post = [
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_facebook' => 'Hello',
        ];

        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn($post);

        $repostAccount = [
            'id' => 2,
            'platform' => 'facebook',
            'account_kind' => 'root',
            'browser_session_id' => 8,
            'sub_page_id' => null,
            'display_name' => 'Jamie Facebook',
        ];
        $primaryAccount = [
            'id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'browser_session_id' => 7,
            'sub_page_id' => '123',
            'display_name' => 'EntryZen Facebook',
        ];

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->willReturnCallback(
            fn (int $id) => match ($id) {
                2 => $repostAccount,
                1 => $primaryAccount,
                default => null,
            }
        );

        $primaryRow = ['id' => 50, 'external_post_url' => ''];
        $primaryRowResolved = ['id' => 50, 'external_post_url' => 'https://facebook.com/posts/99'];

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        // First findPrimarySuccess returns empty URL; subsequent calls return the resolved URL,
        // simulating either the recovery writing it back or another worker populating it.
        $publicationDao->method('findPrimarySuccess')->willReturnOnConsecutiveCalls(
            $primaryRow,
            $primaryRow,
            $primaryRowResolved,
        );
        $publicationDao->method('create')->willReturn(200);
        $publicationDao->method('findByPostId')->willReturn([
            [
                'id' => 200,
                'session_account_id' => 2,
                'publish_batch_id' => 'batch1',
                'status' => 'success',
            ],
        ]);
        $publicationDao->expects($this->atLeastOnce())->method('update');

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturn([
            'session_account_id' => 1,
            'platform' => 'facebook',
            'account_kind' => 'sub',
            'sub_page_id' => '123',
            'display_name' => 'EntryZen Facebook',
        ]);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturnMap([
            ['browser_repost_delay_ms', 45000, 0],
        ]);

        $poster = $this->createMock(PosterAutomationService::class);
        // First resolution attempt fails (post not in feed yet), second succeeds.
        $poster->method('resolveFacebookPrimaryUrl')->willReturnOnConsecutiveCalls(
            ['success' => false, 'error' => 'not found'],
            ['success' => true, 'postUrl' => 'https://facebook.com/posts/99'],
        );
        $poster->expects($this->once())
            ->method('publishFacebookRepost')
            ->with(8, $this->callback(fn (array $payload) => ($payload['primaryPostUrl'] ?? '') === 'https://facebook.com/posts/99'))
            ->willReturn(['success' => true, 'verified' => true, 'postUrl' => 'https://facebook.com/share/99']);

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'postingDao' => $postingDao,
            'settings' => $settings,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext([
            'publish_batch_id' => 'batch1',
            'allow_republish' => true,
        ]);

        $result = $orchestrator->executePublishStepByAccount(10, 2, 'repost', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['data']['step_status']);
    }

    public function testRepostFailsWithDistinctMessageWhenPrimaryUrlCannotBeResolved(): void
    {
        $post = [
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_facebook' => 'Hello',
        ];

        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn($post);

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->willReturn([
            'id' => 2,
            'platform' => 'facebook',
            'account_kind' => 'root',
            'browser_session_id' => 8,
            'sub_page_id' => null,
            'display_name' => 'Jamie Facebook',
        ]);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('findPrimarySuccess')->willReturn(['id' => 50, 'external_post_url' => '']);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturn([
            'session_account_id' => 1,
            'platform' => 'facebook',
        ]);

        $accountDao->method('findById')->willReturnCallback(
            fn (int $id) => match ($id) {
                2 => [
                    'id' => 2,
                    'platform' => 'facebook',
                    'account_kind' => 'root',
                    'browser_session_id' => 8,
                    'sub_page_id' => null,
                    'display_name' => 'Jamie Facebook',
                ],
                1 => [
                    'id' => 1,
                    'platform' => 'facebook',
                    'account_kind' => 'sub',
                    'browser_session_id' => 7,
                    'sub_page_id' => '123',
                    'display_name' => 'EntryZen Facebook',
                ],
                default => null,
            }
        );

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturnMap([
            ['browser_repost_delay_ms', 45000, 0],
        ]);

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->method('resolveFacebookPrimaryUrl')->willReturn(['success' => false, 'error' => 'not found']);
        $poster->expects($this->never())->method('publishFacebookRepost');

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'postingDao' => $postingDao,
            'settings' => $settings,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext([
            'publish_batch_id' => 'batch1',
            'allow_republish' => true,
        ]);

        $result = $orchestrator->executePublishStepByAccount(10, 2, 'repost', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('failed', $result['data']['step_status']);
        $this->assertSame(
            'Could not resolve primary facebook post permalink for repost.',
            $result['data']['error']
        );
    }

    public function testRepostFailsWhenNoPrimaryPublicationExists(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn([
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_facebook' => 'Hello',
        ]);

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->willReturn([
            'id' => 2,
            'platform' => 'facebook',
            'account_kind' => 'root',
            'browser_session_id' => 8,
            'sub_page_id' => null,
            'display_name' => 'Jamie Facebook',
        ]);

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('findPrimarySuccess')->willReturn(null);

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->expects($this->never())->method('publishFacebookRepost');

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext([
            'publish_batch_id' => 'batch1',
            'allow_republish' => true,
        ]);

        $result = $orchestrator->executePublishStepByAccount(10, 2, 'repost', 'facebook');

        $this->assertTrue($result['success']);
        $this->assertSame('failed', $result['data']['step_status']);
        $this->assertSame(
            'Primary facebook post is required before reposting.',
            $result['data']['error']
        );
    }

    public function testRepostRecoversLinkedInPrimaryPostUrlWhenEmpty(): void
    {
        $post = [
            'id' => 10,
            'product_profile_id' => 1,
            'status' => 'posted',
            'content_linkedin' => 'Hello LinkedIn',
            'content_facebook' => '',
        ];

        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn($post);

        $repostAccount = [
            'id' => 4,
            'platform' => 'linkedin',
            'account_kind' => 'root',
            'browser_session_id' => 9,
            'sub_page_id' => null,
            'display_name' => 'Jamie LinkedIn',
        ];
        $primaryAccount = [
            'id' => 3,
            'platform' => 'linkedin',
            'account_kind' => 'root',
            'browser_session_id' => 6,
            'sub_page_id' => null,
            'display_name' => 'EntryZen LinkedIn',
        ];

        $accountDao = $this->createMock(SessionAccountDao::class);
        $accountDao->method('findById')->willReturnCallback(
            fn (int $id) => match ($id) {
                4 => $repostAccount,
                3 => $primaryAccount,
                default => null,
            }
        );

        $primaryRow = ['id' => 60, 'external_post_url' => ''];
        $primaryRowResolved = ['id' => 60, 'external_post_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:99/'];

        $publicationDao = $this->createMock(PostPublicationDao::class);
        $publicationDao->method('hasSuccessfulPublication')->willReturn(false);
        $publicationDao->method('findPrimarySuccess')->willReturnOnConsecutiveCalls(
            $primaryRow,
            $primaryRow,
            $primaryRowResolved,
        );
        $publicationDao->method('create')->willReturn(300);
        $publicationDao->method('findByPostId')->willReturn([
            [
                'id' => 300,
                'session_account_id' => 4,
                'publish_batch_id' => 'batch1',
                'status' => 'success',
            ],
        ]);
        $publicationDao->expects($this->atLeastOnce())->method('update');

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturn([
            'session_account_id' => 3,
            'platform' => 'linkedin',
            'account_kind' => 'root',
            'sub_page_id' => null,
            'display_name' => 'EntryZen LinkedIn',
        ]);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturnMap([
            ['browser_repost_delay_ms', 45000, 0],
        ]);

        $poster = $this->createMock(PosterAutomationService::class);
        $poster->method('resolveLinkedInPrimaryUrl')->willReturnOnConsecutiveCalls(
            ['success' => false, 'error' => 'not found'],
            ['success' => true, 'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:99/'],
        );
        $poster->expects($this->once())
            ->method('publishLinkedInRepost')
            ->with(9, $this->callback(fn (array $payload) => ($payload['primaryPostUrl'] ?? '') === 'https://www.linkedin.com/feed/update/urn:li:activity:99/'))
            ->willReturn(['success' => true, 'verified' => true, 'postUrl' => 'https://www.linkedin.com/feed/']);

        $orchestrator = $this->orchestrator([
            'accountDao' => $accountDao,
            'postDao' => $postDao,
            'publicationDao' => $publicationDao,
            'postingDao' => $postingDao,
            'settings' => $settings,
            'poster' => $poster,
        ]);
        $orchestrator->setPublishContext([
            'publish_batch_id' => 'batch1',
            'allow_republish' => true,
        ]);

        $result = $orchestrator->executePublishStepByAccount(10, 4, 'repost', 'linkedin');

        $this->assertTrue($result['success']);
        $this->assertSame('success', $result['data']['step_status']);
    }
}
