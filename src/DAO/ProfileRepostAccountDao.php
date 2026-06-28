<?php

declare(strict_types=1);

namespace App\DAO;

class ProfileRepostAccountDao extends BaseDao
{
    public function findByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pra.*, sa.account_kind, sa.sub_page_id, sa.display_name, sa.browser_session_id,
                    bs.name AS session_name, bs.platform AS session_platform
             FROM profile_repost_accounts pra
             JOIN session_accounts sa ON sa.id = pra.session_account_id
             JOIN browser_sessions bs ON bs.id = sa.browser_session_id
             WHERE pra.product_profile_id = ?
             ORDER BY pra.platform, pra.sort_order, pra.id'
        );
        $stmt->execute([$profileId]);

        return $stmt->fetchAll();
    }

    public function findActiveByProfileId(int $profileId): array
    {
        return $this->findByProfileId($profileId);
    }

    public function replaceForProfile(int $profileId, array $assignments): void
    {
        $this->db->prepare('DELETE FROM profile_repost_accounts WHERE product_profile_id = ?')
            ->execute([$profileId]);

        $stmt = $this->db->prepare(
            'INSERT INTO profile_repost_accounts (product_profile_id, platform, session_account_id, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($assignments as $row) {
            $stmt->execute([
                $profileId,
                $row['platform'],
                $row['session_account_id'],
                $row['sort_order'] ?? 0,
            ]);
        }
    }

    public function countBySessionAccountId(int $sessionAccountId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM profile_repost_accounts WHERE session_account_id = ?'
        );
        $stmt->execute([$sessionAccountId]);

        return (int) $stmt->fetchColumn();
    }

    public function countBySessionId(int $sessionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM profile_repost_accounts pra
             JOIN session_accounts sa ON sa.id = pra.session_account_id
             WHERE sa.browser_session_id = ?'
        );
        $stmt->execute([$sessionId]);

        return (int) $stmt->fetchColumn();
    }
}
