<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BrowserSessionDao;
use App\Support\ServiceResult;

class BrowserSessionService
{
    private const PLATFORMS = ['facebook', 'linkedin'];

    public function __construct(
        private readonly BrowserSessionDao $dao,
        private readonly SessionAccountService $sessionAccounts,
        private readonly EncryptionService $encryption
    ) {
    }

    public function listAll(): array
    {
        $sessions = $this->dao->findAll();
        foreach ($sessions as $i => $session) {
            $sessions[$i]['target_count'] = (int) ($session['profile_ref_count'] ?? 0);
            $sessions[$i]['accounts'] = $this->sessionAccounts->listForSession((int) $session['id']);
        }

        return $sessions;
    }

    public function listByPlatform(string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return [];
        }

        return $this->dao->findByPlatform($platform);
    }

    public function getById(int $id): ?array
    {
        $row = $this->dao->findById($id);
        if ($row === null) {
            return null;
        }
        $row['accounts'] = $this->sessionAccounts->listForSession($id);

        return $row;
    }

    public function createSession(string $name, string $platform): array
    {
        $name = trim($name);
        if ($name === '') {
            return ServiceResult::failure('Session name is required.', 'VALIDATION_ERROR');
        }

        if (!in_array($platform, self::PLATFORMS, true)) {
            return ServiceResult::failure('Invalid platform.', 'VALIDATION_ERROR');
        }

        if ($this->dao->findByName($name) !== null) {
            return ServiceResult::failure('A session with this name already exists.', 'VALIDATION_ERROR');
        }

        $encrypted = $this->encryption->encrypt('{}');
        $id = $this->dao->create([
            'name' => $name,
            'platform' => $platform,
            'storage_state' => $encrypted,
            'status' => 'pending',
        ]);
        $this->sessionAccounts->createRootForSession($id, $name);

        return ServiceResult::success('Session created.', ['id' => $id]);
    }

    public function importSession(int $sessionId, string $storageStateJson): array
    {
        $row = $this->dao->findById($sessionId);
        if ($row === null) {
            return ServiceResult::failure('Session not found.', 'NOT_FOUND');
        }

        json_decode($storageStateJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ServiceResult::failure('Invalid storageState JSON.', 'VALIDATION_ERROR');
        }

        $encrypted = $this->encryption->encrypt($storageStateJson);
        $this->dao->updateStorage($sessionId, $encrypted, 'active');

        return ServiceResult::success('Session imported.');
    }

    /**
     * @return array{path: string, platform: string}|null
     */
    public function writeDecryptedToTemp(int $sessionId): ?array
    {
        $row = $this->dao->findById($sessionId);
        if ($row === null || ($row['status'] ?? '') !== 'active') {
            return null;
        }

        $json = $this->encryption->decrypt($row['storage_state']);
        $platform = (string) $row['platform'];
        $path = sys_get_temp_dir() . '/social_poster_session_' . $sessionId . '_' . getmypid() . '.json';
        file_put_contents($path, $json);

        return ['path' => $path, 'platform' => $platform];
    }

    public function deleteTemp(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function deleteSession(int $id): array
    {
        $row = $this->dao->findById($id);
        if ($row === null) {
            return ServiceResult::failure('Session not found.', 'NOT_FOUND');
        }

        if ($this->dao->countProfileReferences($id) > 0) {
            return ServiceResult::failure(
                'Session is assigned to one or more product profiles. Remove assignments first.',
                'IN_USE'
            );
        }

        $this->dao->delete($id);

        return ServiceResult::success('Session deleted.');
    }

    public function renameSession(int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ServiceResult::failure('Session name is required.', 'VALIDATION_ERROR');
        }

        $row = $this->dao->findById($id);
        if ($row === null) {
            return ServiceResult::failure('Session not found.', 'NOT_FOUND');
        }

        $existing = $this->dao->findByName($name);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            return ServiceResult::failure('A session with this name already exists.', 'VALIDATION_ERROR');
        }

        $this->dao->updateName($id, $name);
        $this->sessionAccounts->syncRootName($id, $name);

        return ServiceResult::success('Session renamed.');
    }

    public function markExpired(int $sessionId, string $error): void
    {
        $this->dao->updateStatus($sessionId, 'expired', $error);
    }
}
