<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PosterAction;

class PosterAutomationService
{
    private const SCRIPT = 'publish-action.js';

    public function __construct(
        private readonly BrowserAutomationService $browser
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publishFacebookPrimary(int $sessionId, array $payload): array
    {
        return $this->run(PosterAction::FACEBOOK_POST, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publishLinkedInPrimary(int $sessionId, array $payload): array
    {
        return $this->run(PosterAction::LINKEDIN_POST, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publishFacebookRepost(int $sessionId, array $payload): array
    {
        return $this->run(PosterAction::FACEBOOK_REPOST, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolveFacebookPrimaryUrl(int $sessionId, array $payload): array
    {
        return $this->run(PosterAction::FACEBOOK_RESOLVE_PRIMARY, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publishLinkedInRepost(int $sessionId, array $payload): array
    {
        return $this->run(PosterAction::LINKEDIN_REPOST, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function run(string $action, int $sessionId, array $payload): array
    {
        $payload['action'] = $action;
        $payload['platform'] = PosterAction::platform($action);

        return $this->browser->runScript(self::SCRIPT, $sessionId, $payload, $payload['platform']);
    }
}
