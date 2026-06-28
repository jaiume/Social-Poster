<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ImageGenerationService;
use PHPUnit\Framework\TestCase;

class ImageGenerationServiceTest extends TestCase
{
    public function testStripVisualConceptFromContent(): void
    {
        $text = "Post body here.\n\nVisual concept: A split image with branding.";
        $this->assertSame('Post body here.', ImageGenerationService::stripVisualConceptFromContent($text));
    }
}
