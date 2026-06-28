<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\PostDao;
use App\Services\PostWorkflowService;
use PHPUnit\Framework\TestCase;

class PostWorkflowServiceTest extends TestCase
{
    private PostWorkflowService $workflow;

    protected function setUp(): void
    {
        $this->workflow = new PostWorkflowService($this->createMock(PostDao::class));
    }

    public function testAllowedActionsForDraft(): void
    {
        $actions = $this->workflow->allowedActions('draft');

        $this->assertContains('approve', $actions);
        $this->assertContains('delete', $actions);
        $this->assertContains('regenerate_image', $actions);
        $this->assertNotContains('regenerate', $actions);
    }

    public function testAllowedActionsForApproved(): void
    {
        $actions = $this->workflow->allowedActions('approved');

        $this->assertContains('unapprove', $actions);
        $this->assertContains('post', $actions);
        $this->assertContains('repost', $actions);
        $this->assertContains('delete', $actions);
        $this->assertContains('regenerate_image', $actions);
    }

    public function testAllowedActionsForPosted(): void
    {
        $actions = $this->workflow->allowedActions('posted');

        $this->assertContains('archive', $actions);
        $this->assertContains('repost', $actions);
    }
}
