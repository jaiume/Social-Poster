<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\SourceFetchService;
use PHPUnit\Framework\TestCase;

class SourceFetchServiceTest extends TestCase
{
    public function testAllowsSubdomainOfProfileRoot(): void
    {
        $service = new SourceFetchService();
        $roots = ['https://www.example.com'];

        $this->assertTrue($service->isUrlAllowed('https://cdn.example.com/style.css', $roots));
        $this->assertTrue($service->isUrlAllowed('https://www.example.com/about', $roots));
        $this->assertFalse($service->isUrlAllowed('https://evil.com/page', $roots));
        $this->assertFalse($service->isUrlAllowed('http://www.example.com/page', $roots));
    }

    public function testIsSitemapPageUrlRejectsBinaryAssetPaths(): void
    {
        $service = new SourceFetchService();
        $method = new \ReflectionMethod(SourceFetchService::class, 'isSitemapPageUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 'https://example.com/favicon.ico'));
        $this->assertFalse($method->invoke($service, 'https://example.com/images/logo.png'));
        $this->assertFalse($method->invoke($service, 'https://example.com/assets/site.css'));
        $this->assertTrue($method->invoke($service, 'https://example.com/about'));
        $this->assertTrue($method->invoke($service, 'https://example.com/services/wifi'));
    }

    public function testFetchPageRejectsPngUrls(): void
    {
        $service = new SourceFetchService();
        $roots = ['https://wifiventures.co.tt'];

        $result = $service->fetchPage('https://wifiventures.co.tt/images/wifiventures/favicon.png', $roots);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not an HTML page', (string) ($result['error'] ?? ''));
    }

    public function testFetchPagesBatchRemainsJsonEncodableWithPngUrl(): void
    {
        $service = new SourceFetchService();
        $roots = ['https://wifiventures.co.tt'];

        $result = $service->fetchPages([
            'https://wifiventures.co.tt/',
            'https://wifiventures.co.tt/images/wifiventures/favicon.png',
        ], $roots);

        json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['pages'][1]['success']);
    }
}
