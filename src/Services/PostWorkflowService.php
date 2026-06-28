<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\PostDao;

class PostWorkflowService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_ARCHIVED = 'archived';

    public const ACTION_APPROVE = 'approve';
    public const ACTION_UNAPPROVE = 'unapprove';
    public const ACTION_POST = 'post';
    public const ACTION_REPOST = 'repost';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_DELETE = 'delete';
    public const ACTION_REGENERATE_IMAGE = 'regenerate_image';

    public function __construct(
        private readonly PostDao $postDao
    ) {
    }

    public function canPerform(string $status, string $action): bool
    {
        return in_array($action, $this->allowedActions($status), true);
    }

    /**
     * @return array<int, string>
     */
    public function allowedActions(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => [self::ACTION_APPROVE, self::ACTION_DELETE, self::ACTION_REGENERATE_IMAGE],
            self::STATUS_APPROVED => [self::ACTION_UNAPPROVE, self::ACTION_POST, self::ACTION_REPOST, self::ACTION_DELETE, self::ACTION_REGENERATE_IMAGE],
            self::STATUS_POSTED => [self::ACTION_ARCHIVE, self::ACTION_REPOST],
            self::STATUS_ARCHIVED => [],
            default => [],
        };
    }

    /**
     * @return array{success: bool, message: string, post?: array<string, mixed>, error?: array<string, string>}
     */
    public function assertAction(int $postId, string $action): array
    {
        $post = $this->postDao->findById($postId);
        if ($post === null) {
            return ['success' => false, 'message' => 'Post not found.', 'error' => ['code' => 'NOT_FOUND']];
        }

        $status = (string) ($post['status'] ?? '');
        if (!$this->canPerform($status, $action)) {
            return [
                'success' => false,
                'message' => 'Action not allowed for post in status: ' . $status,
                'error' => ['code' => 'INVALID_STATE'],
            ];
        }

        return ['success' => true, 'message' => 'OK', 'post' => $post];
    }

    public function transition(int $postId, string $newStatus): void
    {
        $this->postDao->update($postId, ['status' => $newStatus]);
    }
}
