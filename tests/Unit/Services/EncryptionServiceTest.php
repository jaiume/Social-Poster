<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ConfigService;
use App\Services\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigService::reset();
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $service = new EncryptionService();
        $plain = 'secret session json data';
        $encrypted = $service->encrypt($plain);
        $this->assertNotSame($plain, $encrypted);
        $this->assertSame($plain, $service->decrypt($encrypted));
    }
}
