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

    public function testParsesNestedContentObject(): void
    {
        $parsed = PostContentParser::parse('{"content":{"content":"Nested copy","image_prompt":"Nested image"}}');

        $this->assertSame('Nested copy', $parsed['content']);
        $this->assertSame('Nested image', $parsed['image_prompt']);
    }

    public function testRejectsLegacyPlatformFormat(): void
    {
        $parsed = PostContentParser::parse('{"facebook":"One post","linkedin":"One post"}');

        $this->assertNull($parsed);
    }
}
