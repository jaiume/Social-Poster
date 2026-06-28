<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\SessionAccountDao;
use App\Support\ServiceResult;
use App\Support\SessionAccountUrls;

class ProfileAccountService
{
    public function __construct(
        private readonly SessionAccountDao $accountDao,
        private readonly ProfilePostingAccountDao $postingDao,
        private readonly ProfileRepostAccountDao $repostDao
    ) {
    }

    /**
     * @return array{posting: array<int, array<string, mixed>>, repost: array<int, array<string, mixed>>}
     */
    public function getAssignments(int $profileId): array
    {
        return [
            'posting' => $this->postingDao->findByProfileId($profileId),
            'repost' => $this->repostDao->findByProfileId($profileId),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function savePostingAccount(int $profileId, string $platform, array $data): array
    {
        if (!in_array($platform, ['facebook', 'linkedin'], true)) {
            return ServiceResult::failure('Invalid platform.', 'VALIDATION_ERROR');
        }

        $accountId = (int) ($data['session_account_id'] ?? 0);
        if ($accountId <= 0) {
            $this->postingDao->deleteForPlatform($profileId, $platform);

            return ServiceResult::success('Posting account cleared.');
        }

        $account = $this->accountDao->findById($accountId);
        if ($account === null) {
            return ServiceResult::failure('Account not found.', 'NOT_FOUND');
        }
        if (($account['platform'] ?? '') !== $platform) {
            return ServiceResult::failure('Account platform mismatch.', 'VALIDATION_ERROR');
        }

        $this->postingDao->upsert($profileId, $platform, $accountId);

        return ServiceResult::success('Posting account saved.');
    }

    /**
     * @param list<int> $repostAccountIds
     */
    public function saveRepostAccounts(int $profileId, string $platform, array $repostAccountIds): array
    {
        if (!in_array($platform, ['facebook', 'linkedin'], true)) {
            return ServiceResult::failure('Invalid platform.', 'VALIDATION_ERROR');
        }

        $posting = $this->postingDao->findForProfilePlatform($profileId, $platform);
        $postingAccountId = $posting ? (int) $posting['session_account_id'] : 0;

        $rows = [];
        $sort = 0;
        foreach ($repostAccountIds as $rawId) {
            $accountId = (int) $rawId;
            if ($accountId <= 0) {
                continue;
            }
            if ($accountId === $postingAccountId) {
                return ServiceResult::failure('Repost account cannot be the posting account.', 'VALIDATION_ERROR');
            }
            $account = $this->accountDao->findById($accountId);
            if ($account === null || ($account['platform'] ?? '') !== $platform) {
                return ServiceResult::failure('Invalid repost account.', 'VALIDATION_ERROR');
            }
            $rows[] = [
                'platform' => $platform,
                'session_account_id' => $accountId,
                'sort_order' => $sort++,
            ];
        }

        $this->repostDao->replaceForProfile($profileId, array_merge(
            $this->otherPlatformReposts($profileId, $platform),
            $rows
        ));

        return ServiceResult::success('Repost accounts saved.');
    }

    /**
     * @return list<array{platform: string, session_account_id: int, sort_order: int}>
     */
    private function otherPlatformReposts(int $profileId, string $excludePlatform): array
    {
        $out = [];
        foreach ($this->repostDao->findByProfileId($profileId) as $row) {
            if (($row['platform'] ?? '') === $excludePlatform) {
                continue;
            }
            $out[] = [
                'platform' => (string) $row['platform'],
                'session_account_id' => (int) $row['session_account_id'],
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $out;
    }

    public function enrichAccount(array $account): array
    {
        $platform = (string) ($account['platform'] ?? $account['session_platform'] ?? '');
        $kind = (string) ($account['account_kind'] ?? 'root');
        $subId = isset($account['sub_page_id']) ? (string) $account['sub_page_id'] : null;
        $account['bootstrap_url'] = SessionAccountUrls::bootstrapUrl($platform, $kind, $subId);
        $account['personal_context_url'] = SessionAccountUrls::personalContextUrl($platform);

        return $account;
    }
}
