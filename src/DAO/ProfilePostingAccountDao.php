<?php

declare(strict_types=1);

namespace App\DAO;

class ProfilePostingAccountDao extends BaseDao
{
    public function findByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ppa.platform, ppa.session_account_id,
                    sa.account_kind, sa.sub_page_id, sa.display_name, sa.browser_session_id,
                    bs.name AS session_name, bs.platform AS session_platform
             FROM profile_posting_accounts ppa
             JOIN session_accounts sa ON sa.id = ppa.session_account_id
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE ppa.product_profile_id = ?
             ORDER BY ppa.platform'
        );
        $stmt->execute([$profileId]);

        return $stmt->fetchAll();
    }

    public function findForProfilePlatform(int $profileId, string $platform): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ppa.*, sa.account_kind, sa.sub_page_id, sa.display_name, sa.browser_session_id,
                    bs.platform, bs.name AS session_name
             FROM profile_posting_accounts ppa
             JOIN session_accounts sa ON sa.id = ppa.session_account_id
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE ppa.product_profile_id = ? AND ppa.platform = ?'
        );
        $stmt->execute([$profileId, $platform]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsert(int $profileId, string $platform, int $sessionAccountId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO profile_posting_accounts (product_profile_id, platform, session_account_id)
             VALUES (?, ?, ?)
             ON CONFLICT(product_profile_id, platform) DO UPDATE SET session_account_id = excluded.session_account_id'
        );
        $stmt->execute([$profileId, $platform, $sessionAccountId]);
    }

    public function deleteForPlatform(int $profileId, string $platform): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM profile_posting_accounts WHERE product_profile_id = ? AND platform = ?'
        );
        $stmt->execute([$profileId, $platform]);
    }

    public function countBySessionAccountId(int $sessionAccountId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM profile_posting_accounts WHERE session_account_id = ?'
        );
        $stmt->execute([$sessionAccountId]);

        return (int) $stmt->fetchColumn();
    }

    public function countBySessionId(int $sessionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM profile_posting_accounts ppa
             JOIN session_accounts sa ON sa.id = ppa.session_account_id
             WHERE sa.browser_session_id = ?'
        );
        $stmt->execute([$sessionId]);

        return (int) $stmt->fetchColumn();
    }
}
