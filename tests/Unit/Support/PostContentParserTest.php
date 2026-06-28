<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\PostContentParser;
use PHPUnit\Framework\TestCase;

class PostContentParserTest extends TestCase
{
    public function testParsesUnifiedFormat(): void
    {
        $parsed = PostContentParser::parse('{"content":"Hello world","image_prompt":"A sunny scene"}');

        $this->assertSame('Hello world', $parsed['content']);
        $this->assertSame('A sunny scene', $parsed['image_prompt']);
    }

    public function testParsesNestedPlatformObjects(): void
    {
        $json = <<<'JSON'
{
  "facebook": {
    "content": "Facebook post copy",
    "image_prompt": "Facebook image"
  },
  "linkedin": {
    "content": "LinkedIn post copy",
    "image_prompt": "LinkedIn image"
  }
}
JSON;

        $parsed = PostContentParser::parse($json);

        $this->assertSame('Facebook post copy', $parsed['content']);
        $this->assertSame('Facebook image', $parsed['image_prompt']);
    }

    public function testParsesLegacyFlatStrings(): void
    {
        $parsed = PostContentParser::parse('{"facebook":"One post","linkedin":"One post"}');

        $this->assertSame('One post', $parsed['content']);
        $this->assertNull($parsed['image_prompt']);
    }
}
