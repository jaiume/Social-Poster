<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\AppSettingsDao;
use App\Services\EncryptionService;

class AppSettingsService
{
    public function __construct(
        private readonly AppSettingsDao $dao,
        private readonly ?EncryptionService $encryption = null
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->dao->get($key, $default);
        if ($value === null || $value === '') {
            return $default;
        }

        if ($this->dao->isSecret($key) && $this->encryption !== null && str_starts_with($value, 'enc:')) {
            return $this->encryption->decrypt(substr($value, 4));
        }

        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value !== null && $value !== '' ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    public function getAllForDisplay(): array
    {
        return $this->dao->getAll();
    }

    public function set(string $key, string $value): void
    {
        if ($this->dao->isSecret($key) && $this->encryption !== null && $value !== '') {
            $value = 'enc:' . $this->encryption->encrypt($value);
        }
        $this->dao->set($key, $value);
    }

    public function setIfProvided(string $key, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $this->set($key, $value);
        }
    }
}
