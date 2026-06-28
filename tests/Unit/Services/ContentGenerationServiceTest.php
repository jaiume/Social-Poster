<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;
use App\Services\AppSettingsService;
use App\Services\ContentGenerationService;
use App\Services\FetchAgentToolkit;
use App\Services\ImageGuidanceResolver;
use App\Services\OpenRouterClient;
use PHPUnit\Framework\TestCase;

class ContentGenerationServiceTest extends TestCase
{
    public function testGenerateContentOnlyKeepsPostDraftAndReturnsImagePrompt(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findRecentByProfile')->willReturn([]);
        $postDao->expects($this->once())->method('update')->with(
            5,
            $this->callback(function (array $data): bool {
                return ($data['status'] ?? '') === 'draft'
                    && ($data['content_facebook'] ?? '') === 'Hello world'
                    && ($data['content_linkedin'] ?? '') === 'Hello world'
                    && !isset($data['image_path']);
            })
        );

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturnMap([
            ['openrouter_max_agent_turns', 15, 15],
            ['openrouter_max_tool_calls', 10, 10],
            ['openrouter_max_history_posts', 10, 10],
        ]);
        $settings->method('get')->willReturnMap([
            ['openrouter_post_system_prompt', '', ''],
            ['openrouter_model', '', 'test-model'],
        ]);

        $openRouter = $this->createMock(OpenRouterClient::class);
        $openRouter->method('chat')->willReturn([
            'choices' => [[
                'message' => [
                    'content' => '{"content":"Hello world","image_prompt":"A bright office scene"}',
                ],
            ]],
        ]);

        $fetchToolkit = $this->createMock(FetchAgentToolkit::class);
        $fetchToolkit->method('toolDefinitions')->willReturn([]);

        $guidanceResolver = $this->createMock(ImageGuidanceResolver::class);
        $guidanceResolver->method('sourcesFromGuidance')->willReturn([
            ['url' => 'https://example.com', 'label' => null],
        ]);

        $service = new ContentGenerationService(
            $postDao,
            $this->createMock(ProductProfileDao::class),
            $settings,
            $openRouter,
            $fetchToolkit,
            $guidanceResolver
        );

        $result = $service->generateContentOnly(5, 1, [
            'id' => 1,
            'name' => 'Test Product',
            'generate_post_image' => 1,
            'posting_guidance' => 'See https://example.com for details.',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['data']['post_id']);
        $this->assertSame('A bright office scene', $result['data']['image_prompt']);
    }

    public function testGenerateContentOnlyDoesNotSetApprovedStatus(): void
    {
        $postDao = $this->createMock(PostDao::class);
        $postDao->method('findRecentByProfile')->willReturn([]);
        $postDao->method('update')->willReturnCallback(function (int $id, array $data): void {
            $this->assertNotSame('approved', $data['status'] ?? null);
        });

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInt')->willReturn(15);
        $settings->method('get')->willReturn('');

        $openRouter = $this->createMock(OpenRouterClient::class);
        $openRouter->method('chat')->willReturn([
            'choices' => [[
                'message' => ['content' => '{"content":"Post copy only"}'],
            ]],
        ]);

        $fetchToolkit = $this->createMock(FetchAgentToolkit::class);
        $fetchToolkit->method('toolDefinitions')->willReturn([]);

        $guidanceResolver = $this->createMock(ImageGuidanceResolver::class);
        $guidanceResolver->method('sourcesFromGuidance')->willReturn([]);

        $service = new ContentGenerationService(
            $postDao,
            $this->createMock(ProductProfileDao::class),
            $settings,
            $openRouter,
            $fetchToolkit,
            $guidanceResolver
        );

        $result = $service->generateContentOnly(1, 1, ['name' => 'Test', 'generate_post_image' => 0]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['image_prompt'] ?? null);
    }
}
