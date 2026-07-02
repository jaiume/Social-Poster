<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;
use App\DAO\TaskJobDao;
use App\Middleware\CsrfMiddleware;
use App\Services\ContentGenerationService;
use App\Services\ImageGenerationService;
use App\Services\PostWorkflowService;
use App\Services\ProductProfileService;
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
        private readonly ProductProfileDao $profileDao,
        private readonly TaskJobDao $taskJobDao,
        private readonly ProductProfileService $profiles,
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
        $activeTask = null;

        if ($selectedProfileId !== null) {
            $selectedProfile = $this->profiles->getProfile($selectedProfileId);
            if ($selectedProfile !== null) {
                $posts = $this->postDao->findByProfileId($selectedProfileId);
            }
        }

        if ($selectedPostId !== null) {
            $selectedPost = $this->postDao->findById($selectedPostId);
            if ($selectedPost !== null) {
                if ($selectedProfileId === null) {
                    $selectedProfileId = (int) $selectedPost['product_profile_id'];
                    $selectedProfile = $this->profiles->getProfile($selectedProfileId);
                    $posts = $this->postDao->findByProfileId($selectedProfileId);
                }
                $activeTask = $this->taskJobDao->findActiveJobForPost($selectedPostId);
            }
        }

        if ($activeTask === null && isset($params['active_task_id']) && $params['active_task_id'] !== '') {
            $taskRow = $this->taskJobDao->findById((string) $params['active_task_id']);
            if ($taskRow !== null && in_array($taskRow['status'] ?? '', ['pending', 'running'], true)) {
                $activeTask = $taskRow;
                if ($selectedPostId === null && !empty($taskRow['post_id'])) {
                    $selectedPostId = (int) $taskRow['post_id'];
                    $selectedPost = $this->postDao->findById($selectedPostId);
                    if ($selectedPost !== null && $selectedProfileId === null) {
                        $selectedProfileId = (int) $selectedPost['product_profile_id'];
                        $selectedProfile = $this->profiles->getProfile($selectedProfileId);
                        $posts = $this->postDao->findByProfileId($selectedProfileId);
                    }
                }
            }
        }

        if ($activeTask !== null) {
            $activeTask['steps'] = $this->taskJobDao->decodeJsonField($activeTask['steps_json'] ?? null);
        }

        $editingProfile = null;
        if (isset($params['edit_profile'])) {
            $editingProfile = $this->profiles->getProfile((int) $params['edit_profile']);
        }

        return $this->view->render($response, 'posts/workspace.twig', [
            'profiles' => $profiles,
            'selected_profile_id' => $selectedProfileId,
            'selected_profile' => $selectedProfile,
            'posts' => $posts,
            'selected_post' => $selectedPost,
            'active_task' => $activeTask,
            'editing_profile' => $editingProfile,
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

        $content = trim((string) ($post['content'] ?? ''));
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

    private function redirectWithError(ResponseInterface $response, int $postId, string $message): ResponseInterface
    {
        $post = $this->postDao->findById($postId);
        $profileId = $post !== null ? (int) $post['product_profile_id'] : null;

        return $response->withHeader('Location', $this->workspaceUrl($profileId, $postId, ['error' => $message]))->withStatus(302);
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
}
