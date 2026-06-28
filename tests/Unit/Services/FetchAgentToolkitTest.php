<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\FetchAgentToolkit;
use App\Services\SourceFetchService;
use PHPUnit\Framework\TestCase;

class FetchAgentToolkitTest extends TestCase
{
    public function testFetchPageAcceptsMultipleUrls(): void
    {
        $fetch = $this->createMock(SourceFetchService::class);
        $fetch->expects($this->once())
            ->method('fetchPages')
            ->with(
                ['https://entryzen.com/features', 'https://entryzen.com/pricing'],
                ['https://entryzen.com']
            )
            ->willReturn([
                'success' => true,
                'fetched' => 2,
                'failed' => 0,
                'pages' => [
                    ['success' => true, 'url' => 'https://entryzen.com/features', 'title' => 'Features', 'html' => '<main>Features</main>'],
                    ['success' => true, 'url' => 'https://entryzen.com/pricing', 'title' => 'Pricing', 'html' => '<main>Pricing</main>'],
                ],
            ]);

        $toolkit = new FetchAgentToolkit($fetch);
        $result = $toolkit->execute('fetch_page', [
            'urls' => ['https://entryzen.com/features', 'https://entryzen.com/pricing'],
        ], ['https://entryzen.com']);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['pages']);
    }

    public function testFetchPageAcceptsLegacySingleUrlArg(): void
    {
        $fetch = $this->createMock(SourceFetchService::class);
        $fetch->expects($this->once())
            ->method('fetchPages')
            ->with(['https://entryzen.com/features'], ['https://entryzen.com'])
            ->willReturn([
                'success' => true,
                'fetched' => 1,
                'failed' => 0,
                'pages' => [
                    ['success' => true, 'url' => 'https://entryzen.com/features', 'title' => 'Features', 'html' => '<main>Features</main>'],
                ],
            ]);

        $toolkit = new FetchAgentToolkit($fetch);
        $result = $toolkit->execute('fetch_page', ['url' => 'https://entryzen.com/features'], ['https://entryzen.com']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['pages']);
    }

    public function testRedactResultForAuditTruncatesEachFetchedPage(): void
    {
        $toolkit = new FetchAgentToolkit($this->createMock(SourceFetchService::class));
        $longHtml = str_repeat('x', 600);

        $redacted = $toolkit->redactResultForAudit([
            'success' => true,
            'fetched' => 2,
            'failed' => 0,
            'pages' => [
                ['success' => true, 'url' => 'https://example.com/a', 'html' => $longHtml],
                ['success' => true, 'url' => 'https://example.com/b', 'html' => 'short'],
            ],
        ]);

        $this->assertStringContainsString('[truncated in audit]', $redacted['pages'][0]['html']);
        $this->assertSame('short', $redacted['pages'][1]['html']);
    }

    public function testFetchPageToolDefinitionRequiresUrlsArray(): void
    {
        $toolkit = new FetchAgentToolkit($this->createMock(SourceFetchService::class));
        $definitions = $toolkit->toolDefinitions();

        $fetchPage = null;
        foreach ($definitions as $definition) {
            if (($definition['function']['name'] ?? '') === 'fetch_page') {
                $fetchPage = $definition;
                break;
            }
        }

        $this->assertNotNull($fetchPage);
        $this->assertSame(['urls'], $fetchPage['function']['parameters']['required']);
        $this->assertSame('array', $fetchPage['function']['parameters']['properties']['urls']['type']);
    }

    public function testProcessToolCallsEncodesInvalidUtf8Safely(): void
    {
        $fetch = $this->createMock(SourceFetchService::class);
        $fetch->method('fetchPages')
            ->willReturn([
                'success' => true,
                'fetched' => 1,
                'failed' => 0,
                'pages' => [[
                    'success' => true,
                    'url' => 'https://example.com/bad',
                    'title' => null,
                    'html' => "\x89PNG\r\n",
                ]],
            ]);

        $toolkit = new FetchAgentToolkit($fetch);
        $messages = [];
        $toolCalls = [[
            'id' => 'call_1',
            'function' => [
                'name' => 'fetch_page',
                'arguments' => json_encode(['urls' => ['https://example.com/bad']]),
            ],
        ]];

        $processed = $toolkit->processToolCalls($toolCalls, $messages, ['https://example.com'], 0, 5);

        $this->assertCount(1, $processed['audit']);
        $this->assertCount(1, $messages);
        json_decode($messages[0]['content'], true, 512, JSON_THROW_ON_ERROR);
    }
}
