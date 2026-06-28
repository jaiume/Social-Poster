<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\AppSettingsService;
use App\Services\ConfigService;
use App\Services\OpenRouterClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OpenRouterClientTest extends TestCase
{
    public function testAppRefererUsesConfiguredValue(): void
    {
        $client = new OpenRouterClient($this->createMock(AppSettingsService::class));
        $method = new ReflectionMethod(OpenRouterClient::class, 'appReferer');
        $method->setAccessible(true);

        $this->assertSame(
            (string) ConfigService::get('app.openrouter_referer', 'https://social-poster'),
            $method->invoke($client)
        );
    }

    public function testAppTitleUsesAppName(): void
    {
        $client = new OpenRouterClient($this->createMock(AppSettingsService::class));
        $method = new ReflectionMethod(OpenRouterClient::class, 'appTitle');
        $method->setAccessible(true);

        $this->assertSame((string) ConfigService::get('app.name', 'Social-Poster'), $method->invoke($client));
    }

    public function testSessionIdForPost(): void
    {
        $client = new OpenRouterClient($this->createMock(AppSettingsService::class));
        $method = new ReflectionMethod(OpenRouterClient::class, 'sessionIdForPost');
        $method->setAccessible(true);

        $this->assertSame('post-23', $method->invoke($client, 23));
        $this->assertNull($method->invoke($client, null));
        $this->assertNull($method->invoke($client, 0));
    }
}
