<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Middleware\CsrfMiddleware;
use App\Services\Task\TaskEngine;
use App\Services\Task\TaskJobRecovery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class TaskController
{
    public function __construct(
        private readonly TaskEngine $taskEngine,
        private readonly TaskJobRecovery $taskJobRecovery
    ) {
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->releaseSession();

        $result = $this->taskEngine->start($id);
        $code = 200;
        if (!$result['success']) {
            $code = match ($result['error']['code'] ?? '') {
                'NOT_FOUND' => 404,
                default => 400,
            };
        }

        return $this->json($result, $code);
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $result = $this->taskJobRecovery->cancelJob($id);
        $code = 200;
        if (!$result['success']) {
            $code = match ($result['error']['code'] ?? '') {
                'NOT_FOUND' => 404,
                'STILL_RUNNING' => 409,
                default => 400,
            };
        }

        $this->releaseSession();

        return $this->json($result, $code);
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $status = $this->taskEngine->getStatus($id);

        $this->releaseSession();

        return $this->json($status, $status['success'] ? 200 : 404);
    }

    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * @param array{success: bool, message: string, data?: array<string, mixed>, error?: array<string, string>} $payload
     */
    private function json(array $payload, int $code): ResponseInterface
    {
        $response = new Response($code);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
