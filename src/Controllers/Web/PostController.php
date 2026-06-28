<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\DAO\PostDao;
use App\DAO\PostPublicationDao;
use App\DAO\ProductProfileDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\SessionAccountDao;
use App\DAO\TaskJobDao;
use App\Middleware\CsrfMiddleware;
use App\Services\ContentGenerationService;
use App\Services\ImageGenerationService;
use App\Services\PostWorkflowService;
use App\Services\ProductProfileService;
use App\Services\ProfileAccountService;
use App\Services\SessionAccountService;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\TaskEngine;
use App\Support\ImageBytesValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class PostController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PostDao $postDao,
        private readonly PostPublicationDao $publicationDao,
        private readonly ProductProfileDao $profileDao,
        private readonly ProfilePostingAccountDao $postingDao,
        private readonly ProfileRepostAccountDao $repostDao,
        private readonly TaskJobDao $taskJobDao,
        private readonly ProductProfileService $profiles,
        private readonly ProfileAccountService $profileAccounts,
        private readonly SessionAccountService $sessionAccounts,
        private readonly PostWorkflowService $workflow,
        private readonly TaskEngine $taskEngine,
        private readonly ContentGenerationService $contentGeneration,
        private readonly ImageGenerationService $imageGeneration
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $profiles = $this->profileDao->findAll();
        $selectedProfileId = isset($params['profile']) ? (int) $params['profile'] : null;
        $selectedPostId = isset($params['post']) ? (int) $params['post'] : null;

        if ($selectedProfileId === null && $profiles !== []) {
            $selectedProfileId = (int) $profiles[0]['id'];
        }

        $posts = [];
        $selectedPost = null;
        $selectedProfile = null;
        $assignments = [];
        $publications = [];
        $publicationHistory = [];
        $activeTask = null;

        if ($selectedProfileId !== null) {
            $selectedProfile = $this->profiles->getProfile($selectedProfileId);
            if ($selectedProfile !== null) {
                $posts = $this->postDao->findByProfileId($selectedProfileId);
                $assignments = $this->profileAccounts->getAssignments($selectedProfileId);
            }
        }

        if ($selectedPostId !== null) {
            $selectedPost = $this->postDao->findById($selectedPostId);
            if ($selectedPost !== null) {
                if ($selectedProfileId === null) {
                    $selectedProfileId = (int) $selectedPost['product_profile_id'];
                    $selectedProfile = $this->profiles->getProfile($selectedProfileId);
                    $posts = $this->postDao->findByProfileId($selectedProfileId);
                    $assignments = $this->profileAccounts->getAssignments($selectedProfileId);
                }
                $publications = $this->publicationDao->findByPostId($selectedPostId);
                $publicationHistory = $this->groupPublicationsByAccount($publications);
                $activeTask = $this->taskJobDao->findActiveJobForPost($selectedPostId);
            }
        }

        if ($activeTask === null && isset($params['active_task_id']) && $params['active_task_id'] !== '') {
            $taskRow = $this->taskJobDao->findById((string) $params['active_task_id']);
            if ($taskRow !== null && in_array($taskRow['status'] ?? '', ['pending', 'running'], true)) {
                $activeTask = $taskRow;
                if ($selectedPostId === null && !empty($taskRow['post_id'])) {
                    $selectedPostId = (int) $taskRow['post_id'];
                    $this->hydrateSelectedPost(
                        $selectedPostId,
                        $selectedProfileId,
                        $selectedProfile,
                        $posts,
                        $assignments,
                        $selectedPost,
                        $publications,
                        $publicationHistory
                    );
                }
            }
        }

        if ($activeTask !== null) {
            $activeTask['steps'] = $this->taskJobDao->decodeJsonField($activeTask['steps_json'] ?? null);
        }

        $editingProfile = null;
        $sessionAccountsByPlatform = ['facebook' => [], 'linkedin' => []];
        if (isset($params['edit_profile'])) {
            $editingProfile = $this->profiles->getProfile((int) $params['edit_profile']);
            foreach (['facebook', 'linkedin'] as $platform) {
                $sessionAccountsByPlatform[$platform] = $this->sessionAccounts->listByPlatform($platform);
            }
        }

        $publishAccounts = [];
        if ($selectedPost !== null && $selectedProfileId !== null) {
            $status = (string) ($selectedPost['status'] ?? '');
            $publishAccounts = $this->buildPublishAccounts($selectedProfileId, $publications, $status);
        }

        return $this->view->render($response, 'posts/workspace.twig', [
            'profiles' => $profiles,
            'selected_profile_id' => $selectedProfileId,
            'selected_profile' => $selectedProfile,
            'posts' => $posts,
            'selected_post' => $selectedPost,
            'assignments' => $assignments,
            'publications' => $publications,
            'publication_history' => $publicationHistory,
            'publish_accounts' => $publishAccounts,
            'active_task' => $activeTask,
            'editing_profile' => $editingProfile,
            'session_accounts_by_platform' => $sessionAccountsByPlatform,
            'show_profile_modal' => isset($params['new_profile']) || isset($params['edit_profile']) || $editingProfile !== null,
            'profile_form_error' => $params['error'] ?? null,
            'csrf_token' => CsrfMiddleware::generateToken(),
            'flash' => $this->parseFlash($params),
            'workflow' => $this->workflow,
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $post = $this->postDao->findById((int) $id);
        if ($post === null) {
            return $response->withStatus(404);
        }

        return $response
            ->withHeader('Location', '/posts?profile=' . (int) $post['product_profile_id'] . '&post=' . $id)
            ->withStatus(302);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $profileId = (int) ($body['profile_id'] ?? 0);
        if ($profileId <= 0 || $this->profileDao->findById($profileId) === null) {
            return $response->withHeader('Location', '/posts?error=' . rawurlencode('Profile not found.'))->withStatus(302);
        }

        $postId = $this->contentGeneration->createPostForGeneration($profileId);

        try {
            $result = $this->taskEngine->enqueue(PipelineBuilder::RECIPE_GENERATE_POST, [
                'product_profile_id' => $profileId,
                'post_id' => $postId,
            ]);
        } catch (\Throwable $e) {
            $this->postDao->delete($postId);

            return $response->withHeader('Location', $this->workspaceUrl($profileId, null, ['error' => $e->getMessage()]))->withStatus(302);
        }

        if (!$result['success']) {
            $this->postDao->delete($postId);

            return $response->withHeader('Location', $this->workspaceUrl($profileId, null, [
                'error' => $result['message'],
                'active_task_id' => $result['error']['details']['active_job_id'] ?? null,
            ]))->withStatus(302);
        }

        $jobId = (string) ($result['data']['job_id'] ?? '');

        return $response->withHeader('Location', $this->workspaceUrl($profileId, $postId, [
            'active_task_id' => $jobId,
        ]))->withStatus(302);
    }

    public function approve(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->simpleTransition($response, (int) $id, PostWorkflowService::ACTION_APPROVE, 'approved');
    }

    public function unapprove(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->simpleTransition($response, (int) $id, PostWorkflowService::ACTION_UNAPPROVE, 'draft');
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->simpleTransition($response, (int) $id, PostWorkflowService::ACTION_ARCHIVE, 'archived');
    }

    public function post(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->enqueuePublish($request, $response, (int) $id, false);
    }

    public function repost(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->enqueuePublish($request, $response, (int) $id, true);
    }

    public function regenerateImage(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $postId = (int) $id;
        $check = $this->workflow->assertAction($postId, PostWorkflowService::ACTION_REGENERATE_IMAGE);
        if (!$check['success']) {
            return $this->redirectWithError($response, $postId, $check['message']);
        }

        $post = $check['post'];
        $profileId = (int) $post['product_profile_id'];
        $profile = $this->profileDao->findById($profileId);
        if ($profile === null || (int) ($profile['generate_post_image'] ?? 0) !== 1) {
            return $this->redirectWithError($response, $postId, 'Image generation is not enabled for this profile.');
        }

        $content = trim((string) ($post['content_facebook'] ?? $post['content_linkedin'] ?? ''));
        if ($content === '') {
            return $this->redirectWithError($response, $postId, 'Post has no content to base an image on.');
        }

        $this->imageGeneration->clearForPost($postId);

        $seedResult = ['post_id' => $postId];
        $previousResult = $this->taskJobDao->findLatestCompletedGenerationResult($postId);
        if ($previousResult !== null && !empty($previousResult['image_prompt'])) {
            $seedResult['image_prompt'] = (string) $previousResult['image_prompt'];
        }

        try {
            $result = $this->taskEngine->enqueue(PipelineBuilder::RECIPE_REGENERATE_IMAGE, [
                'product_profile_id' => $profileId,
                'post_id' => $postId,
                'seed_result' => $seedResult,
            ]);
        } catch (\Throwable $e) {
            return $this->redirectWithError($response, $postId, $e->getMessage());
        }

        if (!$result['success']) {
            return $response->withHeader('Location', $this->workspaceUrl(
                $profileId,
                $postId,
                ['error' => $result['message'], 'active_task_id' => $result['error']['details']['active_job_id'] ?? null]
            ))->withStatus(302);
        }

        $jobId = (string) ($result['data']['job_id'] ?? '');

        return $response->withHeader('Location', $this->workspaceUrl(
            $profileId,
            $postId,
            ['active_task_id' => $jobId]
        ))->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $postId = (int) $id;
        $check = $this->workflow->assertAction($postId, PostWorkflowService::ACTION_DELETE);
        if (!$check['success']) {
            return $this->redirectWithError($response, $postId, $check['message']);
        }

        $profileId = (int) $check['post']['product_profile_id'];
        $this->imageGeneration->clearForPost($postId);
        $this->postDao->delete($postId);

        return $response->withHeader('Location', '/posts?profile=' . $profileId . '&deleted=1')->withStatus(302);
    }

    public function image(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $post = $this->postDao->findById((int) $id);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $path = ImageGenerationService::resolveAbsolutePath($post);
        if ($path === null) {
            return $response->withStatus(404);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $response->withStatus(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $response
            ->withHeader('Content-Type', ImageBytesValidator::mimeTypeForExtension($extension))
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withBody(new \Slim\Psr7\Stream($stream));
    }

    private function simpleTransition(ResponseInterface $response, int $postId, string $action, string $newStatus): ResponseInterface
    {
        $check = $this->workflow->assertAction($postId, $action);
        if (!$check['success']) {
            return $this->redirectWithError($response, $postId, $check['message']);
        }

        $this->workflow->transition($postId, $newStatus);
        $profileId = (int) $check['post']['product_profile_id'];

        return $response->withHeader('Location', $this->workspaceUrl($profileId, $postId, ['saved' => '1']))->withStatus(302);
    }

    private function enqueuePublish(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $postId,
        bool $isRepost
    ): ResponseInterface {
        $body = (array) $request->getParsedBody();
        $requestedMode = (string) ($body['publish_mode'] ?? '');
        $isPostAll = $requestedMode === 'all';

        if ($isPostAll) {
            $postCheck = $this->workflow->assertAction($postId, PostWorkflowService::ACTION_POST);
            $repostCheck = $this->workflow->assertAction($postId, PostWorkflowService::ACTION_REPOST);
            if (!$postCheck['success'] && !$repostCheck['success']) {
                return $this->redirectWithError(
                    $response,
                    $postId,
                    $postCheck['message'] ?: $repostCheck['message']
                );
            }
            $post = $postCheck['post'] ?? $repostCheck['post'];
        } else {
            $action = $isRepost ? PostWorkflowService::ACTION_REPOST : PostWorkflowService::ACTION_POST;
            $check = $this->workflow->assertAction($postId, $action);
            if (!$check['success']) {
                return $this->redirectWithError($response, $postId, $check['message']);
            }
            $post = $check['post'];
        }

        $accountIds = array_map('intval', (array) ($body['account_ids'] ?? []));
        $accountIds = array_values(array_filter($accountIds, fn (int $id) => $id > 0));

        if ($accountIds === []) {
            $profileId = (int) $post['product_profile_id'];
            $posting = $this->postingDao->findByProfileId($profileId);
            $reposts = $this->repostDao->findByProfileId($profileId);
            foreach ($posting as $row) {
                $accountIds[] = (int) $row['session_account_id'];
            }
            if ($isRepost) {
                $accountIds = [];
                foreach ($reposts as $row) {
                    $accountIds[] = (int) $row['session_account_id'];
                }
            } elseif ($isPostAll) {
                foreach ($reposts as $row) {
                    $accountIds[] = (int) $row['session_account_id'];
                }
            }
        }

        if ($accountIds === []) {
            return $this->redirectWithError($response, $postId, 'No posting accounts configured.');
        }

        $publishMode = $isPostAll ? 'all' : ($isRepost ? 'repost' : 'post');

        $payload = [
            'product_profile_id' => (int) $post['product_profile_id'],
            'post_id' => $postId,
            'account_ids' => $accountIds,
            'allow_republish' => $isRepost,
            'publish_mode' => $publishMode,
            'publish_batch_id' => bin2hex(random_bytes(8)),
        ];

        try {
            $result = $this->taskEngine->enqueue(PipelineBuilder::RECIPE_PUBLISH_POST, $payload);
        } catch (\Throwable $e) {
            return $this->redirectWithError($response, $postId, $e->getMessage());
        }

        if (!$result['success']) {
            return $response->withHeader('Location', $this->workspaceUrl(
                (int) $post['product_profile_id'],
                $postId,
                ['error' => $result['message'], 'active_task_id' => $result['error']['details']['active_job_id'] ?? null]
            ))->withStatus(302);
        }

        $jobId = (string) ($result['data']['job_id'] ?? '');

        return $response->withHeader('Location', $this->workspaceUrl(
            (int) $post['product_profile_id'],
            $postId,
            ['active_task_id' => $jobId]
        ))->withStatus(302);
    }

    private function redirectWithError(ResponseInterface $response, int $postId, string $message): ResponseInterface
    {
        $post = $this->postDao->findById($postId);
        $profileId = $post !== null ? (int) $post['product_profile_id'] : null;

        return $response->withHeader('Location', $this->workspaceUrl($profileId, $postId, ['error' => $message]))->withStatus(302);
    }

    /**
     * @param array<int, array<string, mixed>> $posts
     * @param array{posting: array<int, array<string, mixed>>, repost: array<int, array<string, mixed>>} $assignments
     * @param array<int, array{account: array<string, mixed>, attempts: array<int, array<string, mixed>>}> $publicationHistory
     */
    private function hydrateSelectedPost(
        int $selectedPostId,
        ?int &$selectedProfileId,
        ?array &$selectedProfile,
        array &$posts,
        array &$assignments,
        ?array &$selectedPost,
        array &$publications,
        array &$publicationHistory
    ): void {
        $selectedPost = $this->postDao->findById($selectedPostId);
        if ($selectedPost === null) {
            return;
        }

        if ($selectedProfileId === null) {
            $selectedProfileId = (int) $selectedPost['product_profile_id'];
            $selectedProfile = $this->profiles->getProfile($selectedProfileId);
            $posts = $this->postDao->findByProfileId($selectedProfileId);
            $assignments = $this->profileAccounts->getAssignments($selectedProfileId);
        }

        $publications = $this->publicationDao->findByPostId($selectedPostId);
        $publicationHistory = $this->groupPublicationsByAccount($publications);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function workspaceUrl(?int $profileId, ?int $postId, array $extra = []): string
    {
        $params = [];
        if ($profileId !== null) {
            $params['profile'] = $profileId;
        }
        if ($postId !== null) {
            $params['post'] = $postId;
        }
        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $query = http_build_query($params);

        return '/posts' . ($query !== '' ? '?' . $query : '');
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function parseFlash(array $params): array
    {
        return [
            'saved' => isset($params['saved']),
            'deleted' => isset($params['deleted']),
            'created' => isset($params['created']),
            'profile_saved' => isset($params['profile_saved']),
            'profile_deleted' => isset($params['profile_deleted']),
            'error' => $params['error'] ?? null,
            'active_task_id' => $params['active_task_id'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $publications
     * @return array<int, array{target: array<string, mixed>, attempts: array<int, array<string, mixed>>}>
     */
    private function groupPublicationsByAccount(array $publications): array
    {
        $grouped = [];
        foreach ($publications as $pub) {
            $accountId = (int) $pub['session_account_id'];
            if (!isset($grouped[$accountId])) {
                $grouped[$accountId] = [
                    'account' => [
                        'id' => $accountId,
                        'display_name' => $pub['display_name'] ?? '',
                        'platform' => $pub['platform'] ?? '',
                        'account_kind' => $pub['account_kind'] ?? '',
                    ],
                    'attempts' => [],
                ];
            }
            $grouped[$accountId]['attempts'][] = $pub;
        }

        return array_values($grouped);
    }

    /**
     * Build the per-platform publish card for the post preview.
     *
     * @param array<int, array<string, mixed>> $publications
     * @return array<int, array{
     *   platform: string,
     *   primary: array{account: ?array<string, mixed>, done: bool, show_button: bool, not_configured?: bool},
     *   reposts: array<int, array{account: array<string, mixed>, done: bool, show_button: bool, locked: bool}>,
     *   show_reposts: bool
     * }>
     */
    private function buildPublishAccounts(int $profileId, array $publications, string $status): array
    {
        $actions = $this->workflow->allowedActions($status);
        $canPost = in_array(PostWorkflowService::ACTION_POST, $actions, true);
        $canRepost = in_array(PostWorkflowService::ACTION_REPOST, $actions, true);
        if (!$canPost && !$canRepost) {
            return [];
        }

        $primarySuccessByAccountId = [];
        $repostSuccessByAccountId = [];
        foreach ($publications as $pub) {
            $aid = (int) ($pub['session_account_id'] ?? 0);
            if ($aid <= 0 || ($pub['status'] ?? '') !== 'success') {
                continue;
            }
            if (($pub['action'] ?? 'post') === 'repost') {
                $repostSuccessByAccountId[$aid] = true;
            } else {
                $primarySuccessByAccountId[$aid] = true;
            }
        }

        $grouped = [];
        foreach ($this->postingDao->findByProfileId($profileId) as $row) {
            $platform = (string) $row['platform'];
            $accountId = (int) $row['session_account_id'];
            $account = $this->accountForPublishCard($row, $accountId);
            $grouped[$platform] = [
                'platform' => $platform,
                'primary' => [
                    'account' => $account,
                    'done' => !empty($primarySuccessByAccountId[$accountId]),
                    'has_session' => (int) ($account['browser_session_id'] ?? 0) > 0,
                ],
                'reposts' => [],
            ];
        }

        foreach ($this->repostDao->findByProfileId($profileId) as $row) {
            $platform = (string) $row['platform'];
            if (!isset($grouped[$platform])) {
                continue;
            }
            $accountId = (int) $row['session_account_id'];
            $account = $this->accountForPublishCard($row, $accountId);
            $grouped[$platform]['reposts'][] = [
                'account' => $account,
                'done' => !empty($repostSuccessByAccountId[$accountId]),
                'has_session' => (int) ($account['browser_session_id'] ?? 0) > 0,
            ];
        }

        $result = [];
        foreach (['facebook', 'linkedin'] as $platform) {
            if (!isset($grouped[$platform])) {
                if ($canPost || $canRepost) {
                    $result[] = [
                        'platform' => $platform,
                        'primary' => [
                            'account' => null,
                            'done' => false,
                            'show_button' => false,
                            'not_configured' => true,
                        ],
                        'reposts' => [],
                        'show_reposts' => false,
                    ];
                }
                continue;
            }
            $g = $grouped[$platform];
            $primaryDone = $g['primary']['done'] ?? false;
            $g['primary']['show_button'] = !$primaryDone && ($g['primary']['has_session'] ?? false) && $canPost;

            foreach ($g['reposts'] as $i => $r) {
                $repostDone = $r['done'];
                $g['reposts'][$i]['locked'] = !$primaryDone && !$repostDone;
                $g['reposts'][$i]['show_button'] = $primaryDone && !$repostDone && ($r['has_session'] ?? false) && $canRepost;
            }
            $g['show_reposts'] = !empty($g['reposts']);
            $result[] = $g;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function accountForPublishCard(array $row, int $sessionAccountId): array
    {
        $account = $this->profileAccounts->enrichAccount($row);
        $account['id'] = $sessionAccountId;

        return $account;
    }
}
