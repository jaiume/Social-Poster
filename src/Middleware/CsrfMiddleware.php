<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ConfigService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!(bool) ConfigService::get('security.csrf_enabled', true)) {
            return $handler->handle($request);
        }

        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $token = is_array($body) ? (string) ($body['csrf_token'] ?? '') : '';
        if ($token === '') {
            $token = $request->getHeaderLine('X-CSRF-Token');
        }
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($token === '' || !hash_equals($sessionToken, (string) $token)) {
            $response = new Response();
            $response->getBody()->write('Invalid CSRF token.');

            return $response->withStatus(403);
        }

        return $handler->handle($request);
    }

    public static function generateToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}
