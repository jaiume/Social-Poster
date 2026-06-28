<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\AuthService;
use App\Services\ConfigService;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigService::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        ConfigService::reset();
        $_SESSION = [];
    }

    public function testLoginFailsWithInvalidCredentials(): void
    {
        $service = new AuthService();

        $result = $service->login('admin', 'wrong-password');

        $this->assertFalse($result['success']);
        $this->assertSame('AUTH_FAILED', $result['error']['code']);
        $this->assertFalse($service->isAuthenticated());
    }

    public function testLoginSucceedsWithValidCredentials(): void
    {
        $service = new AuthService();

        $result = $service->login('admin', 'secret');

        $this->assertTrue($result['success']);
        $this->assertTrue($service->isAuthenticated());
    }
}
