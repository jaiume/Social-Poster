<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Middleware\CsrfMiddleware;
use App\Services\BrowserSessionService;
use App\Services\SessionAccountService;
use App\Services\SessionCaptureService;
use App\Support\MobileClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

class SessionController
{
    public function __construct(
        private readonly Twig $view,
        private readonly BrowserSessionService $sessions,
        private readonly SessionAccountService $sessionAccounts,
        private readonly SessionCaptureService $capture
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $all = $this->sessions->listAll();
        $grouped = ['facebook' => [], 'linkedin' => []];
        foreach ($all as $session) {
            $platform = (string) ($session['platform'] ?? '');
            if (isset($grouped[$platform])) {
                $grouped[$platform][] = $session;
            }
        }

        return $this->view->render($response, 'sessions.twig', [
            'sessions_by_platform' => $grouped,
            'sessions' => $all,
            'csrf_token' => CsrfMiddleware::generateToken(),
            'imported' => isset($params['imported']),
            'created' => isset($params['created']),
            'deleted' => isset($params['deleted']),
            'saved' => isset($params['saved']),
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (MobileClient::isLikelyMobile($request)) {
            return $response->withHeader('Location', '/sessions?error=mobile')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $platform = (string) ($body['platform'] ?? '');
        $result = $this->sessions->createSession($name, $platform);

        if (!$result['success']) {
            return $response->withHeader('Location', '/sessions?error=create')->withStatus(302);
        }

        $sessionId = (int) ($result['data']['id'] ?? 0);

        return $response
            ->withHeader('Location', '/sessions/capture/' . $sessionId)
            ->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $result = $this->sessions->deleteSession((int) $id);
        $qs = $result['success']
            ? 'deleted=1'
            : (($result['error']['code'] ?? '') === 'IN_USE' ? 'error=in_use' : 'error=delete');

        return $response->withHeader('Location', '/sessions?' . $qs)->withStatus(302);
    }

    public function addSubAccount(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $result = $this->sessionAccounts->addSubAccount((int) $id, $body);

        return $response->withHeader('Location', '/sessions?' . ($result['success'] ? 'saved=1' : 'error=sub_save'))->withStatus(302);
    }

    public function rename(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $result = $this->sessions->renameSession((int) $id, $name);

        return $response->withHeader('Location', '/sessions?' . ($result['success'] ? 'saved=1' : 'error=rename'))->withStatus(302);
    }

    public function updateSubAccount(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $accountId
    ): ResponseInterface {
        $body = (array) $request->getParsedBody();
        $result = $this->sessionAccounts->updateSubAccount((int) $accountId, $body);

        return $response->withHeader('Location', '/sessions?' . ($result['success'] ? 'saved=1' : 'error=sub_save'))->withStatus(302);
    }

    public function deleteSubAccount(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $accountId
    ): ResponseInterface {
        $result = $this->sessionAccounts->deleteSubAccount((int) $accountId);
        $qs = $result['success'] ? 'saved=1' : (($result['error']['code'] ?? '') === 'IN_USE' ? 'error=sub_in_use' : 'error=sub_delete');

        return $response->withHeader('Location', '/sessions?' . $qs)->withStatus(302);
    }

    public function captureWindow(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        if (MobileClient::isLikelyMobile($request)) {
            return $response->withHeader('Location', '/sessions?error=mobile')->withStatus(302);
        }

        $session = $this->sessions->getById((int) $id);
        if ($session === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'sessions/capture.twig', [
            'session' => $session,
            'csrf_token' => CsrfMiddleware::generateToken(),
        ]);
    }

    public function captureStart(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $result = $this->capture->start((int) $id);

        return $this->json($response, $result, $result['success'] ? 200 : 400);
    }

    public function captureStatus(ServerRequestInterface $request, ResponseInterface $response, string $jobId): ResponseInterface
    {
        $result = $this->capture->getStatus($jobId);

        return $this->json($response, $result, $result['success'] ? 200 : 404);
    }

    public function captureScreenshot(ServerRequestInterface $request, ResponseInterface $response, string $jobId): ResponseInterface
    {
        $path = $this->capture->screenshotPath($jobId);
        if ($path === null) {
            return $response->withStatus(404);
        }

        $body = (string) file_get_contents($path);
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function captureCommand(ServerRequestInterface $request, ResponseInterface $response, string $jobId): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $command = ['action' => $action];

        if ($action === 'click') {
            $command['x'] = (float) ($body['x'] ?? 0);
            $command['y'] = (float) ($body['y'] ?? 0);
        } elseif ($action === 'type') {
            $command['text'] = (string) ($body['text'] ?? '');
        } elseif ($action === 'key') {
            $command['key'] = (string) ($body['key'] ?? '');
        }

        $result = $this->capture->sendCommand($jobId, $command);

        return $this->json($response, $result, $result['success'] ? 200 : 400);
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (MobileClient::isLikelyMobile($request)) {
            return $response->withHeader('Location', '/sessions?error=mobile')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $sessionId = (int) ($body['session_id'] ?? 0);
        $json = trim((string) ($body['storage_state'] ?? ''));

        if ($json === '' && !empty($_FILES['storage_file']['tmp_name'])) {
            $json = (string) file_get_contents($_FILES['storage_file']['tmp_name']);
        }

        $result = $this->sessions->importSession($sessionId, $json);
        $qs = $result['success'] ? 'imported=1' : 'error=import';

        return $response->withHeader('Location', '/sessions?' . $qs)->withStatus(302);
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
