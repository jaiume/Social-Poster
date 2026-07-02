<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\ProductProfileDao;
use App\Services\ProductProfileService;
use PHPUnit\Framework\TestCase;

class ProductProfileServiceTest extends TestCase
{
    public function testGetProfileReturnsProfile(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->with(1)->willReturn([
            'id' => 1,
            'name' => 'Test',
            'slug' => 'test',
        ]);

        $service = new ProductProfileService($profileDao);
        $profile = $service->getProfile(1);

        $this->assertNotNull($profile);
        $this->assertSame('Test', $profile['name']);
    }
}
