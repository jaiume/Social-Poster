<?php

declare(strict_types=1);

namespace App\DAO;

class TaskJobDao extends BaseDao
{
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM task_jobs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): string
    {
        $id = $data['id'] ?? bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'INSERT INTO task_jobs (
                id, recipe, payload_json, status, steps_json, current_step, result_json,
                product_profile_id, post_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['recipe'],
            $data['payload_json'] ?? '{}',
            $data['status'] ?? 'pending',
            $data['steps_json'] ?? '[]',
            $data['current_step'] ?? 0,
            $data['result_json'] ?? '{}',
            $data['product_profile_id'] ?? null,
            $data['post_id'] ?? null,
        ]);

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $fields = [];
        $values = [];
        $allowed = [
            'recipe', 'payload_json', 'status', 'steps_json', 'current_step', 'pid',
            'error_message', 'result_json', 'product_profile_id', 'post_id',
            'started_at', 'finished_at',
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
        $sql = 'UPDATE task_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
    }

    public function hasActiveJobForProfile(int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM task_jobs
             WHERE product_profile_id = ? AND status IN ('pending', 'running')
             LIMIT 1"
        );
        $stmt->execute([$profileId]);

        return (bool) $stmt->fetchColumn();
    }

    public function hasAnyActiveJob(): bool
    {
        return $this->findActiveJobId() !== null;
    }

    public function findActiveJobId(): ?string
    {
        $stmt = $this->db->query(
            "SELECT id FROM task_jobs
             WHERE status IN ('pending', 'running')
             ORDER BY created_at ASC
             LIMIT 1"
        );
        $id = $stmt->fetchColumn();

        return $id !== false ? (string) $id : null;
    }

    public function findActiveJobForPost(int $postId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM task_jobs
             WHERE post_id = ? AND status IN ('pending', 'running')
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$postId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function tryClaimJob(string $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE task_jobs
             SET status = 'running',
                 pid = ?,
                 started_at = COALESCE(started_at, datetime('now')),
                 updated_at = datetime('now')
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([getmypid(), $id]);

        return $stmt->rowCount() > 0;
    }

    public function hasActivePublishJob(int $postId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM task_jobs
             WHERE post_id = ? AND recipe = 'publish_post'
               AND status IN ('pending', 'running')
             LIMIT 1"
        );
        $stmt->execute([$postId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecent(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM task_jobs ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->db->query(
            "SELECT * FROM task_jobs WHERE status IN ('pending', 'running') ORDER BY created_at DESC"
        )->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findStaleRunning(int $staleMinutes = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM task_jobs
             WHERE status = 'running'
               AND updated_at < datetime('now', ?)
             ORDER BY updated_at ASC"
        );
        $stmt->execute(['-' . $staleMinutes . ' minutes']);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findStalePending(int $staleSeconds = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM task_jobs
             WHERE status = 'pending'
               AND created_at < datetime('now', ?)
             ORDER BY created_at ASC"
        );
        $stmt->execute(['-' . $staleSeconds . ' seconds']);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestCompletedGenerationResult(int $postId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT result_json FROM task_jobs
             WHERE post_id = ?
               AND recipe IN ('generate_post', 'regenerate_post', 'profile_daily_generate', 'profile_daily_post')
               AND status = 'completed'
             ORDER BY finished_at DESC, created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$postId]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null || $json === '') {
            return null;
        }

        $decoded = $this->decodeJsonField((string) $json);

        return $decoded !== [] ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJsonField(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
