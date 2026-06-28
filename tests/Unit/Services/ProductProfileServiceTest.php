<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\ProductProfileDao;
use App\Services\ProductProfileService;
use App\Services\ProfileAccountService;
use PHPUnit\Framework\TestCase;

class ProductProfileServiceTest extends TestCase
{
    public function testGetProfileIncludesAssignments(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->with(1)->willReturn([
            'id' => 1,
            'name' => 'Test',
            'slug' => 'test',
        ]);

        $accounts = $this->createMock(ProfileAccountService::class);
        $accounts->method('getAssignments')->with(1)->willReturn([
            'posting' => [
                'facebook' => ['session_account_id' => 5, 'platform' => 'facebook'],
            ],
            'repost' => [],
        ]);
        $accounts->method('enrichAccount')->willReturnArgument(0);

        $service = new ProductProfileService($profileDao, $accounts);
        $profile = $service->getProfile(1);

        $this->assertNotNull($profile);
        $this->assertArrayHasKey('posting_accounts', $profile);
        $this->assertArrayHasKey('repost_accounts', $profile);
    }
}
