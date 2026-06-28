<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ImageGuidanceResolver;
use App\Services\SourceFetchService;
use PHPUnit\Framework\TestCase;

class ImageGuidanceResolverTest extends TestCase
{
    public function testExtractUrlsTrimsTrailingPunctuation(): void
    {
        $resolver = new ImageGuidanceResolver(new SourceFetchService());

        $urls = $resolver->extractUrls(
            'Logo: https://www.example.com/logo.png, site: https://www.example.com/about.'
        );

        $this->assertSame(
            ['https://www.example.com/logo.png', 'https://www.example.com/about'],
            $urls
        );
    }

    public function testIsLikelyDirectImageUrl(): void
    {
        $resolver = new ImageGuidanceResolver(new SourceFetchService());

        $this->assertTrue($resolver->isLikelyDirectImageUrl('https://cdn.example.com/assets/logo.webp'));
        $this->assertFalse($resolver->isLikelyDirectImageUrl('https://www.example.com/about'));
    }

    public function testResolveReturnsEmptyWithoutUrls(): void
    {
        $resolver = new ImageGuidanceResolver(new SourceFetchService());

        $result = $resolver->resolve('Use blue and green brand colors.');

        $this->assertSame([], $result['reference_images']);
        $this->assertSame('', $result['page_context']);
    }

    public function testSourcesFromGuidance(): void
    {
        $resolver = new ImageGuidanceResolver(new SourceFetchService());

        $sources = $resolver->sourcesFromGuidance('Product site: https://entryzen.com and docs at https://entryzen.com/docs.');

        $this->assertSame(
            [
                ['url' => 'https://entryzen.com', 'label' => null],
                ['url' => 'https://entryzen.com/docs', 'label' => null],
            ],
            $sources
        );
    }
}
