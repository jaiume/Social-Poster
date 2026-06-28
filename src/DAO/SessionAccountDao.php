<?php

declare(strict_types=1);

namespace App\DAO;

class SessionAccountDao extends BaseDao
{
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT sa.*, bs.platform, bs.name AS session_name, bs.id AS browser_session_id
             FROM session_accounts sa
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE sa.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findRootBySessionId(int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT sa.*, bs.platform, bs.name AS session_name
             FROM session_accounts sa
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE sa.browser_session_id = ? AND sa.account_kind = \'root\' LIMIT 1'
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetch() ?: null;
    }

    public function findBySessionId(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT sa.*, bs.platform, bs.name AS session_name
             FROM session_accounts sa
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE sa.browser_session_id = ? AND sa.is_active = 1
             ORDER BY sa.account_kind, sa.display_name, sa.id'
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll();
    }

    public function findActiveByPlatform(string $platform): array
    {
        $stmt = $this->db->prepare(
            'SELECT sa.*, bs.platform, bs.name AS session_name, bs.id AS browser_session_id
             FROM session_accounts sa
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE bs.platform = ? AND sa.is_active = 1 AND bs.status = \'active\'
             ORDER BY bs.name, sa.account_kind, sa.display_name, sa.id'
        );
        $stmt->execute([$platform]);

        return $stmt->fetchAll();
    }

    public function createRoot(int $sessionId, string $displayName): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO session_accounts (browser_session_id, account_kind, display_name, is_active)
             VALUES (?, \'root\', ?, 1)'
        );
        $stmt->execute([$sessionId, $displayName]);

        return (int) $this->db->lastInsertId();
    }

    public function createSub(int $sessionId, string $displayName, string $subPageId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO session_accounts (browser_session_id, account_kind, sub_page_id, display_name, is_active)
             VALUES (?, \'sub\', ?, ?, 1)'
        );
        $stmt->execute([$sessionId, trim($subPageId), trim($displayName)]);

        return (int) $this->db->lastInsertId();
    }

    public function updateSub(int $id, string $displayName, string $subPageId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE session_accounts SET display_name = ?, sub_page_id = ?, updated_at = datetime(\'now\')
             WHERE id = ? AND account_kind = \'sub\''
        );
        $stmt->execute([trim($displayName), trim($subPageId), $id]);
    }

    public function syncRootDisplayName(int $sessionId, string $displayName): void
    {
        $stmt = $this->db->prepare(
            'UPDATE session_accounts SET display_name = ?, updated_at = datetime(\'now\')
             WHERE browser_session_id = ? AND account_kind = \'root\''
        );
        $stmt->execute([trim($displayName), $sessionId]);
    }

    public function deleteSub(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM session_accounts WHERE id = ? AND account_kind = \'sub\''
        );
        $stmt->execute([$id]);
    }

    public function countReferences(int $accountId): int
    {
        $stmt = $this->db->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM profile_posting_accounts WHERE session_account_id = ?)
                + (SELECT COUNT(*) FROM profile_repost_accounts WHERE session_account_id = ?)
             ) AS cnt'
        );
        $stmt->execute([$accountId, $accountId]);

        return (int) $stmt->fetchColumn();
    }

    public function countBySessionId(int $sessionId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM session_accounts WHERE browser_session_id = ?');
        $stmt->execute([$sessionId]);

        return (int) $stmt->fetchColumn();
    }
}
