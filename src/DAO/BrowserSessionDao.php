<?php

declare(strict_types=1);

namespace App\DAO;

class BrowserSessionDao extends BaseDao
{
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM browser_sessions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM browser_sessions WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query(
            'SELECT bs.*, (
                SELECT COUNT(*) FROM session_accounts sa WHERE sa.browser_session_id = bs.id
             ) AS account_count,
             (
                SELECT COUNT(*) FROM profile_posting_accounts ppa
                JOIN session_accounts sa ON sa.id = ppa.session_account_id
                WHERE sa.browser_session_id = bs.id
             ) + (
                SELECT COUNT(*) FROM profile_repost_accounts pra
                JOIN session_accounts sa ON sa.id = pra.session_account_id
                WHERE sa.browser_session_id = bs.id
             ) AS profile_ref_count
             FROM browser_sessions bs
             ORDER BY bs.platform, bs.name'
        )->fetchAll();
    }

    public function findByPlatform(string $platform): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM browser_sessions WHERE platform = ? ORDER BY name'
        );
        $stmt->execute([$platform]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO browser_sessions (name, platform, storage_state, status, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([
            $data['name'],
            $data['platform'],
            $data['storage_state'],
            $data['status'] ?? 'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStorage(int $id, string $encryptedState, string $status = 'active'): void
    {
        $stmt = $this->db->prepare(
            'UPDATE browser_sessions SET storage_state = ?, status = ?, last_error = NULL,
             last_verified_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([$encryptedState, $status, $id]);
    }

    public function updateStatus(int $id, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE browser_sessions SET status = ?, last_error = ?, updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([$status, $error, $id]);
    }

    public function updateName(int $id, string $name): void
    {
        $stmt = $this->db->prepare(
            'UPDATE browser_sessions SET name = ?, updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM browser_sessions WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countProfileReferences(int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM profile_posting_accounts ppa
                 JOIN session_accounts sa ON sa.id = ppa.session_account_id
                 WHERE sa.browser_session_id = ?)
                + (SELECT COUNT(*) FROM profile_repost_accounts pra
                 JOIN session_accounts sa ON sa.id = pra.session_account_id
                 WHERE sa.browser_session_id = ?)
             ) AS cnt'
        );
        $stmt->execute([$id, $id]);

        return (int) $stmt->fetchColumn();
    }
}
