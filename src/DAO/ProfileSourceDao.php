<?php

declare(strict_types=1);

namespace App\DAO;

class ProfileSourceDao extends BaseDao
{
    public function findByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM profile_sources WHERE product_profile_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$profileId]);

        return $stmt->fetchAll();
    }

    public function findActiveByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM profile_sources WHERE product_profile_id = ? AND is_active = 1 ORDER BY sort_order, id'
        );
        $stmt->execute([$profileId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM profile_sources WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO profile_sources (product_profile_id, url, label, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['product_profile_id'],
            $data['url'],
            $data['label'] ?? null,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE profile_sources SET url = ?, label = ?, sort_order = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([
            $data['url'],
            $data['label'] ?? null,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM profile_sources WHERE id = ?');
        $stmt->execute([$id]);
    }
}
