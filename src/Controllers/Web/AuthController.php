<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Twig $view
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->authService->isAuthenticated()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $error = $request->getQueryParams()['error'] ?? null;

        return $this->view->render($response, 'login.twig', [
            'error' => $error,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $result = $this->authService->login($username, $password);

        if (!$result['success']) {
            return $response
                ->withHeader('Location', '/login?error=1')
                ->withStatus(302);
        }

        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->authService->logout();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
