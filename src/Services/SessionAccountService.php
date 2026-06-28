<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BrowserSessionDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\SessionAccountDao;
use App\Support\ServiceResult;
use App\Support\SessionAccountUrls;

class SessionAccountService
{
    public function __construct(
        private readonly SessionAccountDao $accountDao,
        private readonly BrowserSessionDao $sessionDao,
        private readonly ProfilePostingAccountDao $postingDao,
        private readonly ProfileRepostAccountDao $repostDao
    ) {
    }

    public function createRootForSession(int $sessionId, string $displayName): int
    {
        return $this->accountDao->createRoot($sessionId, $displayName);
    }

    public function listForSession(int $sessionId): array
    {
        return $this->accountDao->findBySessionId($sessionId);
    }

    public function listByPlatform(string $platform): array
    {
        return $this->accountDao->findActiveByPlatform($platform);
    }

    public function getById(int $id): ?array
    {
        return $this->accountDao->findById($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addSubAccount(int $sessionId, array $data): array
    {
        $session = $this->sessionDao->findById($sessionId);
        if ($session === null) {
            return ServiceResult::failure('Session not found.', 'NOT_FOUND');
        }

        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            return ServiceResult::failure('Page name is required.', 'VALIDATION_ERROR');
        }

        try {
            $subPageId = SessionAccountUrls::normalizeSubPageLocator(
                (string) $session['platform'],
                (string) ($data['sub_page_id'] ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            return ServiceResult::failure($e->getMessage(), 'VALIDATION_ERROR');
        }

        $id = $this->accountDao->createSub($sessionId, $name, $subPageId);

        return ServiceResult::success('Sub-page added.', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSubAccount(int $accountId, array $data): array
    {
        $account = $this->accountDao->findById($accountId);
        if ($account === null || ($account['account_kind'] ?? '') !== 'sub') {
            return ServiceResult::failure('Sub-page not found.', 'NOT_FOUND');
        }

        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            return ServiceResult::failure('Page name is required.', 'VALIDATION_ERROR');
        }

        try {
            $subPageId = SessionAccountUrls::normalizeSubPageLocator(
                (string) $account['platform'],
                (string) ($data['sub_page_id'] ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            return ServiceResult::failure($e->getMessage(), 'VALIDATION_ERROR');
        }

        $this->accountDao->updateSub($accountId, $name, $subPageId);

        return ServiceResult::success('Sub-page updated.');
    }

    public function deleteSubAccount(int $accountId): array
    {
        if ($this->accountDao->countReferences($accountId) > 0) {
            return ServiceResult::failure('Account is assigned to a profile.', 'IN_USE');
        }

        $this->accountDao->deleteSub($accountId);

        return ServiceResult::success('Sub-page deleted.');
    }

    public function syncRootName(int $sessionId, string $name): void
    {
        $this->accountDao->syncRootDisplayName($sessionId, $name);
    }

    public function countProfileReferencesForSession(int $sessionId): int
    {
        return $this->postingDao->countBySessionId($sessionId)
            + $this->repostDao->countBySessionId($sessionId);
    }
}
