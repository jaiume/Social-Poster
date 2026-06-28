<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\ProductProfileDao;
use App\Services\PostingSchedulerService;
use App\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

class PostingSchedulerServiceTest extends TestCase
{
    public function testGetDueProfilesReturnsEmptyWhileDisabled(): void
    {
        TestDatabase::resetProfiles();
        $pdo = TestDatabase::connection();

        $profiles = new ProductProfileDao($pdo);
        $profiles->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'posting_timezone' => 'UTC',
        ]);

        $scheduler = new PostingSchedulerService($profiles);

        $this->assertSame([], $scheduler->getDueProfiles());
    }
}
