<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ProductProfileDao;
use App\Support\ServiceResult;

class ProductProfileService
{
    public function __construct(
        private readonly ProductProfileDao $profileDao
    ) {
    }

    public function listProfiles(): array
    {
        return $this->profileDao->findAll();
    }

    public function getProfile(int $id): ?array
    {
        return $this->profileDao->findById($id);
    }

    public function saveProfile(?int $id, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ServiceResult::failure('Profile name is required.', 'VALIDATION_ERROR');
        }
        $data['name'] = $name;

        $slug = $this->slugify($name);
        if ($id === null) {
            $existing = $this->profileDao->findBySlug($slug);
            if ($existing !== null) {
                $slug .= '-' . time();
            }
            $data['slug'] = $slug;
            $newId = $this->profileDao->create($data);

            return ServiceResult::success('Profile created.', ['id' => $newId]);
        }

        $data['slug'] = $slug;
        $this->profileDao->update($id, $data);

        return ServiceResult::success('Profile updated.');
    }

    public function deleteProfile(int $id): array
    {
        $this->profileDao->delete($id);

        return ServiceResult::success('Profile deleted.');
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'profile';
    }
}
