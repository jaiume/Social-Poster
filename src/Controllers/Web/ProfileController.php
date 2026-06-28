<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\ProductProfileService;
use App\Services\ProfileAccountService;
use App\Services\SessionAccountService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ProfileController
{
    public function __construct(
        private readonly ProductProfileService $profiles,
        private readonly ProfileAccountService $accounts,
        private readonly SessionAccountService $sessionAccounts
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $query = isset($params['profile']) ? '?profile=' . (int) $params['profile'] : '';

        return $response->withHeader('Location', '/posts' . $query)->withStatus(302);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/posts?new_profile=1')->withStatus(302);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->parseProfile($request);
        if ($data['name'] === '') {
            return $response->withHeader('Location', '/posts?new_profile=1&error=name')->withStatus(302);
        }

        try {
            $result = $this->profiles->saveProfile(null, $data);
        } catch (\Throwable) {
            return $response->withHeader('Location', '/posts?new_profile=1&error=save')->withStatus(302);
        }

        if (!$result['success']) {
            $code = str_contains($result['message'], 'timezone') ? 'timezone' : 'save';

            return $response->withHeader('Location', '/posts?new_profile=1&error=' . $code)->withStatus(302);
        }

        return $response->withHeader('Location', '/posts?profile=' . $result['data']['id'] . '&profile_saved=1')->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $response->withHeader('Location', '/posts?profile=' . (int) $id . '&edit_profile=' . (int) $id)->withStatus(302);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $profileId = (int) $id;
        $data = $this->parseProfile($request);
        $result = $this->profiles->saveProfile($profileId, $data);
        if (!$result['success']) {
            $code = str_contains($result['message'], 'timezone') ? 'timezone' : 'save';

            return $response->withHeader('Location', '/posts?profile=' . $profileId . '&edit_profile=' . $profileId . '&error=' . $code)->withStatus(302);
        }

        return $response->withHeader('Location', '/posts?profile=' . $profileId . '&profile_saved=1')->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->profiles->deleteProfile((int) $id);

        return $response->withHeader('Location', '/posts?profile_deleted=1')->withStatus(302);
    }

    public function savePostingAccount(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $profileId = (int) $id;
        $body = (array) $request->getParsedBody();
        $platform = (string) ($body['platform'] ?? '');
        $result = $this->accounts->savePostingAccount($profileId, $platform, [
            'session_account_id' => (int) ($body['session_account_id'] ?? 0),
        ]);

        return $this->accountRedirect($response, $profileId, $result);
    }

    public function saveRepostAccounts(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $profileId = (int) $id;
        $body = (array) $request->getParsedBody();
        $platform = (string) ($body['platform'] ?? '');
        $ids = array_map('intval', (array) ($body['repost_account_ids'] ?? []));

        $result = $this->accounts->saveRepostAccounts($profileId, $platform, $ids);

        return $this->accountRedirect($response, $profileId, $result);
    }

    private function parseProfile(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();

        return [
            'name' => trim((string) ($body['name'] ?? '')),
            'is_active' => isset($body['is_active']) ? 1 : 0,
            'posting_timezone' => (string) ($body['posting_timezone'] ?? 'Europe/London'),
            'posting_guidance' => trim((string) ($body['posting_guidance'] ?? '')) ?: null,
            'image_guidance' => trim((string) ($body['image_guidance'] ?? '')) ?: null,
            'generate_post_image' => isset($body['generate_post_image']) ? 1 : 0,
        ];
    }

    private function accountRedirect(ResponseInterface $response, int $profileId, array $result): ResponseInterface
    {
        $base = '/posts?profile=' . $profileId . '&edit_profile=' . $profileId;
        if ($result['success']) {
            return $response->withHeader('Location', $base)->withStatus(302);
        }

        $code = 'account_save';
        if (str_contains($result['message'] ?? '', 'posting account')) {
            $code = 'account_repost_conflict';
        }

        return $response->withHeader('Location', $base . '&error=' . $code)->withStatus(302);
    }
}
