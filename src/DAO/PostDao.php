<?php

declare(strict_types=1);

namespace App\DAO;

class PostDao extends BaseDao
{
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM posts WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findRecentByProfile(int $profileId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM posts WHERE product_profile_id = ? AND status = \'posted\'
             ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $profileId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findByProfileId(int $profileId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM posts WHERE product_profile_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $profileId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findAllRecent(int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT * FROM posts ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM posts WHERE status = ? ORDER BY updated_at ASC, id ASC LIMIT ?'
        );
        $stmt->bindValue(1, $status, \PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO posts (product_profile_id, status, source_urls_json)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $data['product_profile_id'],
            $data['status'] ?? 'draft',
            $data['source_urls_json'] ?? '[]',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];
        $allowed = [
            'status', 'content_facebook', 'content_linkedin', 'source_urls_json',
            'ai_model', 'ai_prompt_snapshot', 'ai_tool_calls_json', 'ai_error', 'generated_at',
            'image_path', 'image_error',
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
        $fields[] = "updated_at = datetime('now')";
        $values[] = $id;
        $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM posts WHERE id = ?');
        $stmt->execute([$id]);
    }
}
