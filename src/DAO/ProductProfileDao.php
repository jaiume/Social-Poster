<?php

declare(strict_types=1);

namespace App\DAO;

use App\Support\SqlDialect;

class ProductProfileDao extends BaseDao
{
    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM product_profiles ORDER BY name')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM product_profiles WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM product_profiles WHERE slug = ?');
        $stmt->execute([$slug]);

        return $stmt->fetch() ?: null;
    }

    public function findActive(): array
    {
        return $this->db->query('SELECT * FROM product_profiles WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_profiles (name, slug, is_active, posting_guidance, image_guidance, generate_post_image)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['is_active'] ?? 1,
            $data['posting_guidance'] ?? null,
            $data['image_guidance'] ?? null,
            $data['generate_post_image'] ?? 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_profiles SET name = ?, slug = ?, is_active = ?,
             posting_guidance = ?, image_guidance = ?, generate_post_image = ?, updated_at = '
            . SqlDialect::now() . ' WHERE id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['is_active'] ?? 1,
            $data['posting_guidance'] ?? null,
            $data['image_guidance'] ?? null,
            $data['generate_post_image'] ?? 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_profiles WHERE id = ?');
        $stmt->execute([$id]);
    }
}
