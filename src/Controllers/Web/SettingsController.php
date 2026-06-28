<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Middleware\CsrfMiddleware;
use App\Services\AppSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class SettingsController
{
    private const EDITABLE_KEYS = [
        'openrouter_api_key',
        'openrouter_model',
        'openrouter_image_model',
        'openrouter_max_tool_calls',
        'openrouter_max_agent_turns',
        'openrouter_post_system_prompt',
        'openrouter_image_system_prompt',
        'openrouter_max_history_posts',
        'browser_timeout_ms',
        'browser_repost_delay_ms',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly AppSettingsService $settings
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'settings.twig', [
            'settings' => $this->settings->getAllForDisplay(),
            'csrf_token' => CsrfMiddleware::generateToken(),
            'saved' => isset($params['saved']),
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        foreach (self::EDITABLE_KEYS as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = trim((string) $body[$key]);
            if ($key === 'openrouter_api_key' && $value === '') {
                continue;
            }
            $this->settings->setIfProvided($key, $value);
        }

        return $response->withHeader('Location', '/settings?saved=1')->withStatus(302);
    }
}
