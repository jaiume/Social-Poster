<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\MobileClient;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class MobileClientTest extends TestCase
{
    public function testDetectsMobileUserAgent(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/sessions')
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');

        $this->assertTrue(MobileClient::isLikelyMobile($request));
    }

    public function testDetectsDesktopUserAgent(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/sessions')
            ->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0');

        $this->assertFalse(MobileClient::isLikelyMobile($request));
    }

    public function testDetectsNarrowViewportHeader(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/sessions')
            ->withHeader('Sec-CH-Viewport-Width', '390');

        $this->assertTrue(MobileClient::isLikelyMobile($request));
    }
}
