<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\BrowserAutomationService;
use App\Services\PosterAutomationService;
use App\Support\PosterAction;
use PHPUnit\Framework\TestCase;

class PosterAutomationServiceTest extends TestCase
{
    public function testPublishFacebookPrimarySetsActionInPayload(): void
    {
        $browser = $this->createMock(BrowserAutomationService::class);
        $browser->expects($this->once())
            ->method('runScript')
            ->with(
                'publish-action.js',
                7,
                $this->callback(static function (array $payload): bool {
                    return $payload['action'] === PosterAction::FACEBOOK_POST
                        && $payload['platform'] === 'facebook'
                        && $payload['text'] === 'Hello';
                }),
                'facebook'
            )
            ->willReturn(['success' => true]);

        $service = new PosterAutomationService($browser);
        $result = $service->publishFacebookPrimary(7, ['text' => 'Hello']);

        $this->assertTrue($result['success']);
    }

    public function testPublishLinkedInRepostSetsActionInPayload(): void
    {
        $browser = $this->createMock(BrowserAutomationService::class);
        $browser->expects($this->once())
            ->method('runScript')
            ->with(
                'publish-action.js',
                3,
                $this->callback(static function (array $payload): bool {
                    return $payload['action'] === PosterAction::LINKEDIN_REPOST
                        && $payload['platform'] === 'linkedin'
                        && $payload['primaryPostUrl'] === 'https://linkedin.com/post/1';
                }),
                'linkedin'
            )
            ->willReturn(['success' => true]);

        $service = new PosterAutomationService($browser);
        $result = $service->publishLinkedInRepost(3, ['primaryPostUrl' => 'https://linkedin.com/post/1']);

        $this->assertTrue($result['success']);
    }
}
