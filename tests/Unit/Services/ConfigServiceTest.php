<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ConfigService;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigService::reset();
    }

    public function testGetReturnsDottedKeyValue(): void
    {
        $this->assertSame('Social Poster Test', ConfigService::get('app.name'));
        $this->assertSame('sqlite', ConfigService::get('database.driver'));
        $this->assertSame('default-value', ConfigService::get('missing.key', 'default-value'));
    }
}
