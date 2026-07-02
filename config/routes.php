<?php

declare(strict_types=1);

use App\Controllers\Web\AuthController;
use App\Controllers\Web\PostController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\SettingsController;
use App\Controllers\Web\TaskController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Services\ConfigService;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return function (App $app): void {
    $app->addBodyParsingMiddleware();
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));

    $app->add(function ($request, $handler) use ($app) {
        if (session_status() === PHP_SESSION_NONE) {
            session_name((string) ConfigService::get('security.session_name', 'social_poster_session'));
            session_start();
        }

        $container = $app->getContainer();
        if ($container?->has(Twig::class)) {
            $twig = $container->get(Twig::class);
            $twig->getEnvironment()->addGlobal('csrf_token', CsrfMiddleware::generateToken());
        }

        return $handler->handle($request);
    });

    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->get('/favicon.ico', function ($request, $response) {
        return $response->withStatus(204);
    });
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout'])->add(CsrfMiddleware::class);

    $app->group('', function (RouteCollectorProxy $group) {
        $group->get('/', [PostController::class, 'index']);
        $group->get('/profiles', [ProfileController::class, 'index']);
        $group->get('/profiles/create', [ProfileController::class, 'create']);
        $group->post('/profiles/create', [ProfileController::class, 'store'])->add(CsrfMiddleware::class);
        $group->get('/profiles/{id}', [ProfileController::class, 'edit']);
        $group->post('/profiles/{id}', [ProfileController::class, 'update'])->add(CsrfMiddleware::class);
        $group->post('/profiles/{id}/delete', [ProfileController::class, 'delete'])->add(CsrfMiddleware::class);
        $group->get('/settings', [SettingsController::class, 'index']);
        $group->post('/settings', [SettingsController::class, 'save'])->add(CsrfMiddleware::class);
        $group->get('/posts', [PostController::class, 'index']);
        $group->post('/posts/create', [PostController::class, 'create'])->add(CsrfMiddleware::class);
        $group->get('/posts/{id}', [PostController::class, 'show']);
        $group->get('/posts/{id}/image', [PostController::class, 'image']);
        $group->post('/posts/{id}/approve', [PostController::class, 'approve'])->add(CsrfMiddleware::class);
        $group->post('/posts/{id}/unapprove', [PostController::class, 'unapprove'])->add(CsrfMiddleware::class);
        $group->post('/posts/{id}/archive', [PostController::class, 'archive'])->add(CsrfMiddleware::class);
        $group->post('/posts/{id}/regenerate-image', [PostController::class, 'regenerateImage'])->add(CsrfMiddleware::class);
        $group->post('/posts/{id}/delete', [PostController::class, 'delete'])->add(CsrfMiddleware::class);
        $group->post('/tasks/{id}/start', [TaskController::class, 'start'])->add(CsrfMiddleware::class);
        $group->post('/tasks/{id}/cancel', [TaskController::class, 'cancel'])->add(CsrfMiddleware::class);
        $group->get('/tasks/{id}/status', [TaskController::class, 'status']);
    })->add(AuthMiddleware::class);
};
