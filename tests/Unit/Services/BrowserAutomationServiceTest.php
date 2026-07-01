<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\BrowserAutomationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BrowserAutomationServiceTest extends TestCase
{
    private BrowserAutomationService $service;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(BrowserAutomationService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    private function invokePrivate(string $method, array $args)
    {
        $reflection = new ReflectionClass(BrowserAutomationService::class);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($this->service, $args);
    }

    public function testStderrExcerptReturnsEmptyForBlankStderr(): void
    {
        $this->assertSame('', $this->invokePrivate('stderrExcerpt', ['']));
        $this->assertSame('', $this->invokePrivate('stderrExcerpt', ["   \n  \n"]));
    }

    public function testStderrExcerptReturnsLastNonEmptyLines(): void
    {
        $stderr = "[facebook] Navigating to page\n[facebook] Opening Page Posts tab\n\n[facebook] Clicking Next\n[facebook] Publishing timed out\n";
        $excerpt = $this->invokePrivate('stderrExcerpt', [$stderr]);

        $this->assertStringContainsString('Publishing timed out', $excerpt);
        $this->assertStringContainsString('Clicking Next', $excerpt);
        $this->assertStringNotContainsString('Navigating to page', $excerpt);
    }

    public function testStderrExcerptTruncatesLongTail(): void
    {
        $stderr = str_repeat('x', 1000);
        $excerpt = $this->invokePrivate('stderrExcerpt', [$stderr, 50]);

        $this->assertLessThanOrEqual(53, strlen($excerpt));
        $this->assertStringStartsWith('...', $excerpt);
    }

    public function testWriteTimeoutDiagnosticsWritesFileWithContext(): void
    {
        $path = $this->invokePrivate('writeTimeoutDiagnostics', [
            'publish-action.js',
            42,
            ['action' => 'facebook.post', 'platform' => 'facebook', 'pageUrl' => 'https://www.facebook.com/example'],
            '{"partial":true}',
            "[facebook] Opening create post dialog\n[facebook] Waiting for composer\n",
        ]);

        $this->assertIsString($path);
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('script: publish-action.js', $contents);
        $this->assertStringContainsString('sessionId: 42', $contents);
        $this->assertStringContainsString('action: facebook.post', $contents);
        $this->assertStringContainsString('pageUrl: https://www.facebook.com/example', $contents);
        $this->assertStringContainsString('Waiting for composer', $contents);

        unlink($path);
    }
}
