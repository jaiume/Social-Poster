<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\PostDao;
use App\DAO\PostPublicationDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\PublicationAttemptStateDao;
use App\DAO\ProductProfileDao;
use App\DAO\SessionAccountDao;
use App\DAO\TaskJobDao;
use App\Services\Task\PipelineBuilder;
use App\Support\PosterAction;
use App\Support\ServiceResult;
use App\Support\SessionAccountUrls;

class PostingOrchestratorService
{
    private const FB_MAX_CHARS = 5000;
    private const LI_MAX_CHARS = 3000;

    /** @var array<string, mixed>|null */
    private ?array $publishContext = null;

    public function __construct(
        private readonly ProductProfileDao $profileDao,
        private readonly SessionAccountDao $accountDao,
        private readonly ProfilePostingAccountDao $postingDao,
        private readonly PostDao $postDao,
        private readonly PostPublicationDao $publicationDao,
        private readonly PublicationAttemptStateDao $attemptStateDao,
        private readonly AppSettingsService $settings,
        private readonly PosterAutomationService $poster,
        private readonly PublishPlanBuilder $planBuilder,
        private readonly PipelineBuilder $pipelineBuilder,
        private readonly TaskJobDao $taskJobDao
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setPublishContext(array $context): void
    {
        $this->publishContext = $context;
    }

    public function clearPublishContext(): void
    {
        $this->publishContext = null;
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function executePublishStepByAccount(
        int $postId,
        int $sessionAccountId,
        string $action,
        string $platform
    ): array {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ServiceResult::failure('Post not found.', 'NOT_FOUND');
        }

        $account = $this->accountDao->findById($sessionAccountId);
        if ($account === null || ($account['platform'] ?? '') !== $platform) {
            return ServiceResult::failure('Publish account not found.', 'NOT_FOUND');
        }

        $content = $this->truncate(
            $this->postContent($post, $platform),
            $platform === 'facebook' ? self::FB_MAX_CHARS : self::LI_MAX_CHARS
        );
        $imagePath = ImageGenerationService::resolveAbsolutePath($post);
        $batchId = $this->publishBatchId();

        if ($this->shouldSkipExistingSuccess($postId, $sessionAccountId)) {
            return ServiceResult::success('Step skipped.', ['step_status' => 'skipped']);
        }

        if ($action === 'repost') {
            $primary = $this->publicationDao->findPrimarySuccess($postId, $platform);
            if ($primary === null) {
                return ServiceResult::success('Repost failed.', [
                    'step_status' => 'failed',
                    'error' => 'Primary ' . $platform . ' post is required before reposting.',
                ]);
            }

            $primaryUrl = $this->resolvePrimaryUrlWithBackoff($postId, $platform, $primary, $content, $post);
            if ($primaryUrl === '') {
                return ServiceResult::success('Repost failed.', [
                    'step_status' => 'failed',
                    'error' => 'Could not resolve primary ' . $platform . ' post permalink for repost.',
                ]);
            }

            $delayMs = $this->settings->getInt('browser_repost_delay_ms', 45000);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $this->publishRepost(
                $postId,
                (int) $post['product_profile_id'],
                $account,
                $platform,
                $primaryUrl,
                (int) $primary['id'],
                $content,
                $batchId
            );
            $success = $this->batchPublicationSucceeded($postId, $sessionAccountId, $batchId);

            return ServiceResult::success('Step completed.', [
                'step_status' => $success ? 'success' : 'failed',
                'error' => $success ? null : ($this->latestPublicationError($postId, $sessionAccountId) ?? 'Repost failed.'),
            ]);
        }

        $pubResult = $this->publishPrimary($postId, $account, $content, $platform, $imagePath, $batchId);
        $success = $pubResult['pubId'] !== null && ($pubResult['success'] ?? false);

        return ServiceResult::success('Step completed.', [
            'step_status' => $success ? 'success' : 'failed',
            'error' => $success ? null : ($pubResult['error'] ?? 'Post failed.'),
        ]);
    }

    /** @deprecated use executePublishStepByAccount */
    public function executePublishStepByTarget(
        int $postId,
        int $sessionAccountId,
        string $action,
        string $platform
    ): array {
        return $this->executePublishStepByAccount($postId, $sessionAccountId, $action, $platform);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function beginPublishing(int $postId, array $context = []): array
    {
        $this->publishContext = $context;

        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ServiceResult::failure('Post not found.', 'NOT_FOUND');
        }

        if ($this->postContent($post, 'facebook') === '' && $this->postContent($post, 'linkedin') === '') {
            return ServiceResult::failure('Post has no content to publish.', 'VALIDATION_ERROR');
        }

        $status = (string) ($post['status'] ?? '');
        $allowRepost = (bool) ($context['allow_republish'] ?? false);
        $publishMode = (string) ($context['publish_mode'] ?? 'all');
        $canContinueWhenPosted = $allowRepost || in_array($publishMode, ['all', 'repost'], true);
        if (!in_array($status, ['approved', 'posted'], true)) {
            return ServiceResult::failure('Post cannot be published in its current state.', 'INVALID_STATE');
        }
        if ($status === 'posted' && !$canContinueWhenPosted && $this->allPrimariesSucceeded($postId)) {
            return ServiceResult::failure('Use repost for already posted content.', 'INVALID_STATE');
        }

        if (empty($context['publish_batch_id'])) {
            $context['publish_batch_id'] = bin2hex(random_bytes(8));
            $this->publishContext = $context;
        }

        return ServiceResult::success('Publishing started.', [
            'post_id' => $postId,
            'publish_batch_id' => $context['publish_batch_id'],
            'steps' => $this->buildPublishPlan($postId, $context),
        ]);
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>}
     */
    public function completePublishing(int $postId): array
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ServiceResult::failure('Post not found.', 'NOT_FOUND');
        }

        $batchId = $this->publishBatchId();
        $previousStatus = (string) ($post['status'] ?? '');
        $anySuccess = $batchId !== null
            ? $this->publicationDao->hasSuccessfulBatchPublication($postId, $batchId)
            : $this->anyPlatformPublishSucceeded($postId);

        $status = $previousStatus;
        if ($this->allPrimariesSucceeded($postId)) {
            $status = 'posted';
        } elseif ($anySuccess) {
            $status = 'approved';
        } elseif ($previousStatus === 'approved') {
            $status = 'approved';
        }

        $this->postDao->update($postId, ['status' => $status]);
        $this->clearPublishContext();

        return ServiceResult::success('Publishing completed.', [
            'post_id' => $postId,
            'status' => $status,
            'any_success' => $anySuccess,
            'finished' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{label: string, platform: string, action: string, session_account_id: int}>
     */
    public function buildPublishPlan(int $postId, array $context = []): array
    {
        if ($this->publishContext !== null) {
            $context = array_merge($this->publishContext, $context);
        }

        return $this->planBuilder->build($postId, $context);
    }

    private function shouldSkipExistingSuccess(int $postId, int $sessionAccountId): bool
    {
        if ((bool) ($this->publishContext['allow_republish'] ?? false)) {
            return false;
        }

        return $this->publicationDao->hasSuccessfulPublication($postId, $sessionAccountId);
    }

    private function publishBatchId(): ?string
    {
        $batchId = $this->publishContext['publish_batch_id'] ?? null;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }

    private function batchPublicationSucceeded(int $postId, int $sessionAccountId, ?string $batchId): bool
    {
        if ($batchId === null) {
            return $this->publicationDao->hasSuccessfulPublication($postId, $sessionAccountId);
        }

        foreach ($this->publicationDao->findByPostId($postId) as $publication) {
            if (
                (int) $publication['session_account_id'] === $sessionAccountId
                && ($publication['publish_batch_id'] ?? '') === $batchId
                && ($publication['status'] ?? '') === 'success'
            ) {
                return true;
            }
        }

        return false;
    }

    private function anyPlatformPublishSucceeded(int $postId): bool
    {
        foreach (['facebook', 'linkedin'] as $platform) {
            if ($this->platformPublishSucceeded($postId, $platform)) {
                return true;
            }
        }

        return false;
    }

    private function allPrimariesSucceeded(int $postId): bool
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return false;
        }

        $profileId = (int) $post['product_profile_id'];
        $configured = 0;
        $succeeded = 0;

        foreach (['facebook', 'linkedin'] as $platform) {
            $slot = $this->postingDao->findForProfilePlatform($profileId, $platform);
            if ($slot === null) {
                continue;
            }
            $configured++;
            if ($this->publicationDao->findPrimarySuccess($postId, $platform) !== null) {
                $succeeded++;
            }
        }

        return $configured > 0 && $configured === $succeeded;
    }

    private function platformPublishSucceeded(int $postId, string $platform): bool
    {
        return $this->publicationDao->findPrimarySuccess($postId, $platform) !== null;
    }

    /**
     * @return array{pubId: ?int, success: bool, error: ?string}
     */
    private function publishPrimary(
        int $postId,
        array $account,
        string $content,
        string $platform,
        ?string $imagePath,
        ?string $batchId
    ): array {
        $accountId = (int) $account['id'];
        if ($this->shouldSkipExistingSuccess($postId, $accountId)) {
            $existing = $this->publicationDao->findPrimarySuccess($postId, $platform);

            return ['pubId' => $existing ? (int) $existing['id'] : null, 'success' => true, 'error' => null];
        }

        $pubId = $this->publicationDao->create([
            'post_id' => $postId,
            'session_account_id' => $accountId,
            'action' => 'post',
            'browser_method' => 'poster_action',
            'publish_batch_id' => $batchId,
        ]);

        $sessionId = (int) ($account['browser_session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->failPublication($pubId, 'NO_SESSION', 'Account has no browser session.');

            return ['pubId' => null, 'success' => false, 'error' => 'Account has no browser session.'];
        }

        $payload = $this->buildPosterPayload($account, $platform, $content, $imagePath);
        $result = $this->runPosterPrimary($platform, $sessionId, $payload);
        $this->publicationDao->update($pubId, ['attempted_at' => gmdate('c')]);

        $pageUrl = (string) ($payload['pageUrl'] ?? '');
        if ($this->isSuccessfulBrowserResult($result)) {
            $postUrl = trim((string) ($result['postUrl'] ?? ''));
            if ($postUrl === '' && $platform === 'facebook') {
                $post = $this->postDao->findById($postId);
                if ($post !== null) {
                    // Use the same retry-with-backoff as repost recovery: a post
                    // frequently isn't visible in the page feed the instant it's
                    // published, so a single immediate attempt is more likely to
                    // fail than one that gives Facebook a few seconds to index it.
                    $postUrl = $this->resolvePrimaryUrlWithBackoff(
                        $postId,
                        $platform,
                        ['id' => $pubId, 'external_post_url' => ''],
                        $content,
                        $post
                    );
                }
            }
            $this->publicationDao->update($pubId, [
                'status' => 'success',
                'external_post_url' => $postUrl,
                'completed_at' => gmdate('c'),
            ]);
            if ($postUrl === '') {
                $result['evidence'] = array_merge(
                    $result['evidence'] ?? [],
                    ['note' => 'Primary post published but permalink was not captured; will be resolved lazily on repost.']
                );
            }
            $this->recordAttemptState($pubId, $platform, 'post', $pageUrl, 'success', $result);

            return ['pubId' => $pubId, 'success' => true, 'error' => null];
        }

        $error = (string) ($result['error'] ?? 'Post failed.');
        $this->failPublication($pubId, (string) ($result['errorCode'] ?? 'AUTOMATION_ERROR'), $error);
        $this->recordAttemptState($pubId, $platform, 'post', $pageUrl, 'failed', $result);

        return ['pubId' => null, 'success' => false, 'error' => $error];
    }

    private function publishRepost(
        int $postId,
        int $profileId,
        array $account,
        string $platform,
        string $primaryUrl,
        int $parentPubId,
        string $content,
        ?string $batchId
    ): void {
        $accountId = (int) $account['id'];
        if ($this->shouldSkipExistingSuccess($postId, $accountId)) {
            return;
        }

        $pubId = $this->publicationDao->create([
            'post_id' => $postId,
            'session_account_id' => $accountId,
            'action' => 'repost',
            'browser_method' => 'poster_action',
            'parent_publication_id' => $parentPubId,
            'publish_batch_id' => $batchId,
        ]);

        $sessionId = (int) ($account['browser_session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->failPublication($pubId, 'NO_SESSION', 'Account has no browser session.');

            return;
        }

        $posting = $this->postingDao->findForProfilePlatform($profileId, $platform);
        $primaryPageUrl = $posting !== null
            ? SessionAccountUrls::bootstrapUrlForAccount($posting)
            : SessionAccountUrls::personalContextUrl($platform);
        $primaryPageBrand = null;
        if ($posting !== null) {
            $postingAccount = $this->accountDao->findById((int) $posting['session_account_id']);
            if ($postingAccount !== null) {
                $primaryPageBrand = SessionAccountUrls::primaryPageBrandFromDisplayName(
                    $platform,
                    (string) ($postingAccount['display_name'] ?? '')
                );
            }
        }

        $payload = $this->buildRepostPayload($account, $platform, $content, $primaryUrl, $primaryPageUrl, $primaryPageBrand);
        $result = $this->runPosterRepost($platform, $sessionId, $payload);
        $this->publicationDao->update($pubId, ['attempted_at' => gmdate('c')]);

        $verifyUrl = SessionAccountUrls::personalContextUrl($platform);
        if ($this->isSuccessfulRepostResult($result)) {
            $this->publicationDao->update($pubId, [
                'status' => 'success',
                'external_post_url' => (string) ($result['postUrl'] ?? $primaryUrl),
                'completed_at' => gmdate('c'),
            ]);
            $this->recordAttemptState($pubId, $platform, 'repost', $verifyUrl, 'success', $result);

            return;
        }

        $error = (string) ($result['error'] ?? 'Repost failed.');
        $this->failPublication($pubId, (string) ($result['errorCode'] ?? 'AUTOMATION_ERROR'), $error);
        $this->recordAttemptState($pubId, $platform, 'repost', $verifyUrl, 'failed', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPosterPayload(array $account, string $platform, string $content, ?string $imagePath): array
    {
        $kind = (string) ($account['account_kind'] ?? 'root');
        $subId = isset($account['sub_page_id']) ? (string) $account['sub_page_id'] : null;
        $bootstrap = SessionAccountUrls::bootstrapUrl($platform, $kind, $subId);

        $payload = [
            'text' => $content,
            'accountKind' => $kind,
            'pageUrl' => $bootstrap,
            'operatorStartUrl' => $bootstrap,
            'personalContextUrl' => SessionAccountUrls::personalContextUrl($platform),
            'subPageId' => $subId,
        ];
        if ($imagePath !== null && $imagePath !== '') {
            $payload['imagePath'] = $imagePath;
        }
        if ($kind === 'sub') {
            $brand = SessionAccountUrls::primaryPageBrandFromDisplayName(
                $platform,
                (string) ($account['display_name'] ?? '')
            );
            if ($brand !== null && $brand !== '') {
                $payload['primaryPageBrand'] = $brand;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<string, mixed> $post
     */
    private function recoverPrimaryPostUrl(
        int $postId,
        string $platform,
        ?array $primary,
        string $content,
        array $post
    ): string {
        if ($primary === null) {
            return '';
        }

        $existing = trim((string) ($primary['external_post_url'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $profileId = (int) $post['product_profile_id'];
        $posting = $this->postingDao->findForProfilePlatform($profileId, $platform);
        if ($posting === null) {
            return '';
        }

        $account = $this->accountDao->findById((int) $posting['session_account_id']);
        if ($account === null) {
            return '';
        }

        $sessionId = (int) ($account['browser_session_id'] ?? 0);
        if ($sessionId <= 0) {
            return '';
        }

        $payload = $this->buildPosterPayload($account, $platform, $content, null);
        $result = $this->resolvePrimaryUrl($platform, $sessionId, $payload);
        if (!$this->isSuccessfulBrowserResult($result)) {
            return '';
        }

        $url = trim((string) ($result['postUrl'] ?? ''));
        if ($url === '') {
            return '';
        }

        $this->publicationDao->update((int) $primary['id'], ['external_post_url' => $url]);

        return $url;
    }

    private const PRIMARY_URL_RECOVERY_MAX_ATTEMPTS = 3;
    private const PRIMARY_URL_RECOVERY_SLEEP_SECONDS = 7;

    /**
     * Resolve a primary post URL for reposting, retrying when the post
     * has not yet propagated to the page feed. Re-reads the primary row
     * from the DAO on each attempt so a concurrent worker that has
     * populated the URL is respected.
     *
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $post
     */
    private function resolvePrimaryUrlWithBackoff(
        int $postId,
        string $platform,
        array $primary,
        string $content,
        array $post
    ): string {
        $attempts = max(1, self::PRIMARY_URL_RECOVERY_MAX_ATTEMPTS);
        for ($i = 1; $i <= $attempts; $i++) {
            $primary = $this->publicationDao->findPrimarySuccess($postId, $platform) ?? $primary;
            $url = $this->recoverPrimaryPostUrl($postId, $platform, $primary, $content, $post);
            if ($url !== '') {
                return $url;
            }

            if ($i < $attempts) {
                sleep(self::PRIMARY_URL_RECOVERY_SLEEP_SECONDS);
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRepostPayload(
        array $account,
        string $platform,
        string $content,
        string $primaryPostUrl,
        string $primaryPageUrl,
        ?string $primaryPageBrand = null
    ): array {
        $kind = (string) ($account['account_kind'] ?? 'root');
        $personal = SessionAccountUrls::personalContextUrl($platform);

        $payload = [
            'text' => $content,
            'accountKind' => $kind,
            'primaryPostUrl' => $primaryPostUrl,
            'primaryPageUrl' => $primaryPageUrl,
            'pageUrl' => $personal,
            'operatorStartUrl' => $personal,
            'personalContextUrl' => $personal,
            'targetPageUrl' => $personal,
            'subPageId' => $account['sub_page_id'] ?? null,
        ];
        if ($primaryPageBrand !== null && $primaryPageBrand !== '') {
            $payload['primaryPageBrand'] = $primaryPageBrand;
        }
        $memberName = SessionAccountUrls::memberNameFromDisplayName(
            $platform,
            (string) ($account['display_name'] ?? '')
        );
        if ($memberName !== null && $memberName !== '') {
            $payload['personalProfileName'] = $memberName;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runPosterPrimary(string $platform, int $sessionId, array $payload): array
    {
        return match ($platform) {
            'facebook' => $this->poster->publishFacebookPrimary($sessionId, $payload),
            'linkedin' => $this->poster->publishLinkedInPrimary($sessionId, $payload),
            default => ['success' => false, 'error' => 'Unsupported platform.', 'errorCode' => 'VALIDATION_ERROR'],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runPosterRepost(string $platform, int $sessionId, array $payload): array
    {
        return match ($platform) {
            'facebook' => $this->poster->publishFacebookRepost($sessionId, $payload),
            'linkedin' => $this->poster->publishLinkedInRepost($sessionId, $payload),
            default => ['success' => false, 'error' => 'Unsupported platform.', 'errorCode' => 'VALIDATION_ERROR'],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolvePrimaryUrl(string $platform, int $sessionId, array $payload): array
    {
        return match ($platform) {
            'facebook' => $this->poster->resolveFacebookPrimaryUrl($sessionId, $payload),
            'linkedin' => $this->poster->resolveLinkedInPrimaryUrl($sessionId, $payload),
            default => ['success' => false, 'error' => 'Unsupported platform.', 'errorCode' => 'VALIDATION_ERROR'],
        };
    }

    private function failPublication(int $pubId, string $code, string $message): void
    {
        $this->publicationDao->update($pubId, [
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => gmdate('c'),
        ]);
    }

    private function latestPublicationError(int $postId, int $sessionAccountId): ?string
    {
        foreach (array_reverse($this->publicationDao->findByPostId($postId)) as $publication) {
            if ((int) $publication['session_account_id'] !== $sessionAccountId) {
                continue;
            }
            $message = trim((string) ($publication['error_message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }

    private function postContent(array $post, string $platform): string
    {
        $field = $platform === 'facebook' ? 'content_facebook' : 'content_linkedin';
        $primary = trim((string) ($post[$field] ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        $fallback = trim((string) ($post['content_facebook'] ?? $post['content_linkedin'] ?? ''));

        return $fallback;
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isSuccessfulBrowserResult(array $result): bool
    {
        return !empty($result['success']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isSuccessfulRepostResult(array $result): bool
    {
        return !empty($result['success']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function recordAttemptState(
        int $publicationId,
        string $platform,
        string $action,
        ?string $operatorTargetUrl,
        string $status,
        array $result
    ): void {
        try {
            $this->attemptStateDao->create([
                'publication_id' => $publicationId,
                'platform' => $platform,
                'action' => $action,
                'state' => (string) ($result['state'] ?? ($status === 'success' ? 'SUBMITTED' : 'FAILED_NON_RETRYABLE')),
                'status' => $status,
                'attempt_no' => 1,
                'operator_target_url' => $this->sanitizeUrl($operatorTargetUrl),
                'resolved_start_url' => $this->sanitizeUrl((string) ($result['resolvedStartUrl'] ?? '')),
                'resolver_reason_code' => $result['resolverReasonCode'] ?? 'POSTER_ACTION',
                'resolver_confidence' => $result['resolverConfidence'] ?? null,
                'verification_confidence' => null,
                'evidence_json' => $this->encodeEvidence($result['evidence'] ?? null),
                'error_code' => $result['errorCode'] ?? null,
                'error_class' => $result['errorClass'] ?? null,
                'retryable' => (bool) ($result['retryable'] ?? false),
                'started_at' => gmdate('c'),
                'ended_at' => gmdate('c'),
            ]);
        } catch (\Throwable) {
        }
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        return trim($url);
    }

    /**
     * @param mixed $evidence
     */
    private function encodeEvidence($evidence): string
    {
        if (!is_array($evidence) || $evidence === []) {
            return '{}';
        }

        $json = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
