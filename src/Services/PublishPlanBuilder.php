<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\PostDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;

class PublishPlanBuilder
{
    public function __construct(
        private readonly PostDao $postDao,
        private readonly ProfilePostingAccountDao $postingDao,
        private readonly ProfileRepostAccountDao $repostDao
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{label: string, platform: string, action: string, session_account_id: int}>
     */
    public function build(int $postId, array $context = []): array
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return [];
        }

        $accountIds = $context['account_ids'] ?? null;
        if (is_array($accountIds)) {
            $accountIds = array_map('intval', $accountIds);
        }

        $profileId = (int) $post['product_profile_id'];
        $steps = [];
        $publishMode = (string) ($context['publish_mode'] ?? 'all');
        $includePrimary = $publishMode === 'all' || $publishMode === 'post';
        $includeRepost = $publishMode === 'all' || $publishMode === 'repost';

        if ($includePrimary) {
        foreach (['facebook', 'linkedin'] as $platform) {
            $posting = $this->postingDao->findForProfilePlatform($profileId, $platform);
            if ($posting === null) {
                continue;
            }
            $accountId = (int) $posting['session_account_id'];
            if (is_array($accountIds) && !in_array($accountId, $accountIds, true)) {
                continue;
            }

            $steps[] = [
                'label' => $platform === 'facebook' ? 'Posting to Facebook' : 'Posting to LinkedIn',
                'platform' => $platform,
                'action' => 'post',
                'session_account_id' => $accountId,
            ];
        }
        }

        if ($includeRepost) {
        foreach (['facebook', 'linkedin'] as $platform) {
            foreach ($this->repostDao->findByProfileId($profileId) as $repost) {
                if (($repost['platform'] ?? '') !== $platform) {
                    continue;
                }
                $accountId = (int) $repost['session_account_id'];
                if (is_array($accountIds) && !in_array($accountId, $accountIds, true)) {
                    continue;
                }

                $steps[] = [
                    'label' => $platform === 'facebook' ? 'Reposting on Facebook' : 'Reposting on LinkedIn',
                    'platform' => $platform,
                    'action' => 'repost',
                    'session_account_id' => $accountId,
                ];
            }
        }
        }

        return $steps;
    }
}
