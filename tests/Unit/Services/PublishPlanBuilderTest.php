<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\PostDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\Services\PublishPlanBuilder;
use PHPUnit\Framework\TestCase;

class PublishPlanBuilderTest extends TestCase
{
    public function testRepostModeDoesNotIncludePrimaryStepsWhenAccountIdCollidesWithRepostRowId(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn(['id' => 62, 'product_profile_id' => 16]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 4],
                'linkedin' => ['session_account_id' => 5],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['platform' => 'facebook', 'session_account_id' => 1, 'id' => 5],
            ['platform' => 'linkedin', 'session_account_id' => 2, 'id' => 6],
        ]);

        $builder = new PublishPlanBuilder($postDao, $postingDao, $repostDao);

        $steps = $builder->build(62, [
            'account_ids' => [5],
            'publish_mode' => 'repost',
        ]);

        $this->assertSame([], $steps);
    }

    public function testRepostModeIncludesOnlyMatchingRepostAccount(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn(['id' => 62, 'product_profile_id' => 16]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 4],
                'linkedin' => ['session_account_id' => 5],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['platform' => 'facebook', 'session_account_id' => 1, 'id' => 5],
            ['platform' => 'linkedin', 'session_account_id' => 2, 'id' => 6],
        ]);

        $builder = new PublishPlanBuilder($postDao, $postingDao, $repostDao);

        $steps = $builder->build(62, [
            'account_ids' => [1],
            'publish_mode' => 'repost',
        ]);

        $this->assertCount(1, $steps);
        $this->assertSame('repost', $steps[0]['action']);
        $this->assertSame(1, $steps[0]['session_account_id']);
    }

    public function testPostModeDoesNotIncludeRepostSteps(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn(['id' => 62, 'product_profile_id' => 16]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 4],
                'linkedin' => ['session_account_id' => 5],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['platform' => 'facebook', 'session_account_id' => 1, 'id' => 5],
        ]);

        $builder = new PublishPlanBuilder($postDao, $postingDao, $repostDao);

        $steps = $builder->build(62, [
            'account_ids' => [4],
            'publish_mode' => 'post',
        ]);

        $this->assertCount(1, $steps);
        $this->assertSame('post', $steps[0]['action']);
        $this->assertSame(4, $steps[0]['session_account_id']);
    }

    public function testAllModeOrdersPrimariesBeforeReposts(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findById')->willReturn(['id' => 62, 'product_profile_id' => 16]);

        $postingDao = $this->createMock(ProfilePostingAccountDao::class);
        $postingDao->method('findForProfilePlatform')->willReturnCallback(
            fn (int $profileId, string $platform) => match ($platform) {
                'facebook' => ['session_account_id' => 4],
                'linkedin' => ['session_account_id' => 5],
                default => null,
            }
        );

        $repostDao = $this->createMock(ProfileRepostAccountDao::class);
        $repostDao->method('findByProfileId')->willReturn([
            ['platform' => 'facebook', 'session_account_id' => 1, 'id' => 7],
            ['platform' => 'linkedin', 'session_account_id' => 2, 'id' => 8],
        ]);

        $builder = new PublishPlanBuilder($postDao, $postingDao, $repostDao);

        $steps = $builder->build(62, [
            'account_ids' => [4, 5, 1, 2],
            'publish_mode' => 'all',
        ]);

        $this->assertCount(4, $steps);
        $this->assertSame(
            [
                ['action' => 'post', 'session_account_id' => 4],
                ['action' => 'post', 'session_account_id' => 5],
                ['action' => 'repost', 'session_account_id' => 1],
                ['action' => 'repost', 'session_account_id' => 2],
            ],
            array_map(
                fn (array $step) => [
                    'action' => $step['action'],
                    'session_account_id' => $step['session_account_id'],
                ],
                $steps
            )
        );
    }
}
