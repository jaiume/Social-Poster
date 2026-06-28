<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\SourceFetchService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SourceFetchServiceSitemapTest extends TestCase
{
    public function testUrlSitemapChildrenRejectsDisallowedUrl(): void
    {
        $service = new SourceFetchService();

        $result = $service->urlSitemapChildren('https://evil.com/', ['https://entryzen.com']);

        $this->assertFalse($result['success']);
        $this->assertSame('URL not allowed for this profile.', $result['error']);
    }

    public function testIsUnderPathPrefix(): void
    {
        $service = new SourceFetchService();
        $method = new ReflectionMethod(SourceFetchService::class, 'isUnderPathPrefix');
        $method->setAccessible(true);

        $root = 'https://example.com/docs';

        $this->assertTrue($method->invoke($service, 'https://example.com/docs', $root));
        $this->assertTrue($method->invoke($service, 'https://example.com/docs/guide', $root));
        $this->assertTrue($method->invoke($service, 'https://www.example.com/docs/api', $root));
        $this->assertFalse($method->invoke($service, 'https://example.com/about', $root));
        $this->assertFalse($method->invoke($service, 'https://example.com/documentation', $root));
    }

    public function testBuildSitemapTreeHandlesCyclesAndChildren(): void
    {
        $service = new SourceFetchService();
        $build = new ReflectionMethod(SourceFetchService::class, 'buildSitemapTree');
        $build->setAccessible(true);

        $nodes = [
            'https://example.com/' => [
                'url' => 'https://example.com/',
                'title' => 'Home',
                'child_urls' => ['https://example.com/features', 'https://example.com/'],
            ],
            'https://example.com/features' => [
                'url' => 'https://example.com/features',
                'title' => 'Features',
                'child_urls' => ['https://example.com/'],
            ],
        ];

        $tree = $build->invoke($service, 'https://example.com/', $nodes);

        $this->assertSame('https://example.com/', $tree['url']);
        $this->assertSame('Home', $tree['title']);
        $this->assertCount(1, $tree['children']);
        $this->assertSame('Features', $tree['children'][0]['title']);
        $this->assertSame([], $tree['children'][0]['children']);
    }

    public function testBuildSitemapTreeDoesNotDuplicateCrossLinkedPages(): void
    {
        $service = new SourceFetchService();
        $build = new ReflectionMethod(SourceFetchService::class, 'buildSitemapTree');
        $build->setAccessible(true);

        $nodes = [
            'https://example.com/' => [
                'url' => 'https://example.com/',
                'title' => 'Home',
                'child_urls' => [
                    'https://example.com/features',
                    'https://example.com/pricing',
                    'https://example.com/security',
                    'https://example.com/faq',
                    'https://example.com/contact',
                ],
            ],
            'https://example.com/features' => [
                'url' => 'https://example.com/features',
                'title' => 'Features',
                'child_urls' => [
                    'https://example.com/',
                    'https://example.com/pricing',
                    'https://example.com/security',
                ],
            ],
            'https://example.com/pricing' => [
                'url' => 'https://example.com/pricing',
                'title' => 'Pricing',
                'child_urls' => [
                    'https://example.com/',
                    'https://example.com/features',
                ],
            ],
            'https://example.com/security' => [
                'url' => 'https://example.com/security',
                'title' => 'Security',
                'child_urls' => ['https://example.com/'],
            ],
            'https://example.com/faq' => [
                'url' => 'https://example.com/faq',
                'title' => 'FAQ',
                'child_urls' => ['https://example.com/contact'],
            ],
            'https://example.com/contact' => [
                'url' => 'https://example.com/contact',
                'title' => 'Contact',
                'child_urls' => ['https://example.com/faq'],
            ],
        ];

        $tree = $build->invoke($service, 'https://example.com/', $nodes);
        $nodeCount = $this->countSitemapTreeNodes($tree);

        $this->assertCount(5, $tree['children']);
        $this->assertSame(6, $nodeCount);
        $this->assertLessThan(4096, strlen(json_encode($tree, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function countSitemapTreeNodes(array $tree): int
    {
        $count = 1;
        foreach ($tree['children'] ?? [] as $child) {
            $count += $this->countSitemapTreeNodes($child);
        }

        return $count;
    }

    public function testToolkitExecutesUrlSitemapChildren(): void
    {
        $fetch = $this->createMock(SourceFetchService::class);
        $fetch->expects($this->once())
            ->method('urlSitemapChildren')
            ->with('https://entryzen.com', ['https://entryzen.com'])
            ->willReturn(['success' => true, 'url' => 'https://entryzen.com', 'tree' => [], 'stats' => []]);

        $toolkit = new \App\Services\FetchAgentToolkit($fetch);
        $result = $toolkit->execute('url_sitemap_children', ['url' => 'https://entryzen.com'], ['https://entryzen.com']);

        $this->assertTrue($result['success']);
    }
}
