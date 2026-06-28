<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ProductProfileDao;
use App\Support\ServiceResult;
use App\Support\TimezoneHelper;

class ProductProfileService
{
    public function __construct(
        private readonly ProductProfileDao $profileDao,
        private readonly ProfileAccountService $accountService
    ) {
    }

    public function listProfiles(): array
    {
        return $this->profileDao->findAll();
    }

    public function getProfile(int $id): ?array
    {
        $profile = $this->profileDao->findById($id);
        if ($profile === null) {
            return null;
        }

        $assignments = $this->accountService->getAssignments($id);

        $postingByPlatform = [];
        foreach ($assignments['posting'] as $row) {
            $postingByPlatform[(string) $row['platform']] = $this->accountService->enrichAccount($row);
        }
        $profile['posting_accounts'] = $postingByPlatform;

        $repostByPlatform = ['facebook' => [], 'linkedin' => []];
        foreach ($assignments['repost'] as $row) {
            $platform = (string) $row['platform'];
            $repostByPlatform[$platform][] = $this->accountService->enrichAccount($row);
        }
        $profile['repost_accounts'] = $repostByPlatform;

        return $profile;
    }

    public function saveProfile(?int $id, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ServiceResult::failure('Profile name is required.', 'VALIDATION_ERROR');
        }
        $data['name'] = $name;

        $timezone = TimezoneHelper::normalize((string) ($data['posting_timezone'] ?? 'Europe/London'));
        if (!TimezoneHelper::isValid($timezone)) {
            return ServiceResult::failure('Invalid posting timezone.', 'VALIDATION_ERROR');
        }
        $data['posting_timezone'] = $timezone;

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
