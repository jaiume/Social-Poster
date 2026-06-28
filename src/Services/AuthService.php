<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ServiceResult;

class AuthService
{
    public function login(string $username, string $password): array
    {
        $configuredUsername = (string) ConfigService::get('auth.admin_username', '');
        $configuredPassword = (string) ConfigService::get('auth.admin_password', '');

        if ($configuredUsername === '' || $configuredPassword === '') {
            return ServiceResult::failure(
                'Admin credentials are not configured.',
                'AUTH_NOT_CONFIGURED'
            );
        }

        if (!hash_equals($configuredUsername, $username)) {
            return ServiceResult::failure('Invalid credentials.', 'AUTH_FAILED');
        }

        if (!hash_equals($configuredPassword, $password)) {
            return ServiceResult::failure('Invalid credentials.', 'AUTH_FAILED');
        }

        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $configuredUsername;

        return ServiceResult::success('Logged in successfully.');
    }

    public function logout(): array
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return ServiceResult::success('Logged out successfully.');
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']);
    }
}
