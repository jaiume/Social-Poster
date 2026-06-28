<?php

declare(strict_types=1);

namespace App\DAO;

class PostPublicationDao extends BaseDao
{
    public function findByPostId(int $postId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.*, sa.display_name, sa.account_kind, sa.sub_page_id,
                    bs.platform, bs.name AS session_name
             FROM post_publications pp
             JOIN session_accounts sa ON sa.id = pp.session_account_id
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE pp.post_id = ? ORDER BY pp.id'
        );
        $stmt->execute([$postId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM post_publications WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO post_publications (post_id, session_account_id, action, status, parent_publication_id, browser_method, publish_batch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['post_id'],
            $data['session_account_id'],
            $data['action'],
            $data['status'] ?? 'pending',
            $data['parent_publication_id'] ?? null,
            $data['browser_method'] ?? null,
            $data['publish_batch_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];
        $allowed = [
            'status', 'external_post_url', 'browser_method', 'error_code', 'error_message',
            'attempted_at', 'completed_at', 'publish_batch_id',
        ];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }
        if ($fields === []) {
            return;
        }
        $values[] = $id;
        $sql = 'UPDATE post_publications SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    public function hasSuccessfulPublication(int $postId, int $sessionAccountId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM post_publications WHERE post_id = ? AND session_account_id = ? AND status = \'success\' LIMIT 1'
        );
        $stmt->execute([$postId, $sessionAccountId]);

        return (bool) $stmt->fetchColumn();
    }

    public function hasSuccessfulBatchPublication(int $postId, string $batchId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM post_publications WHERE post_id = ? AND publish_batch_id = ? AND status = \'success\' LIMIT 1'
        );
        $stmt->execute([$postId, $batchId]);

        return (bool) $stmt->fetchColumn();
    }

    public function findPrimarySuccess(int $postId, string $platform): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.* FROM post_publications pp
             JOIN session_accounts sa ON sa.id = pp.session_account_id
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             JOIN profile_posting_accounts ppa ON ppa.session_account_id = sa.id
             JOIN posts p ON p.id = pp.post_id AND p.product_profile_id = ppa.product_profile_id
             WHERE pp.post_id = ? AND ppa.platform = ? AND pp.action = \'post\' AND pp.status = \'success\'
             LIMIT 1'
        );
        $stmt->execute([$postId, $platform]);

        return $stmt->fetch() ?: null;
    }
}
