<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\PlaybookFormat;
use PHPUnit\Framework\TestCase;

class PlaybookFormatTest extends TestCase
{
    public function testIsV2AcceptsSchemaVersionTwo(): void
    {
        $this->assertTrue(PlaybookFormat::isV2(['schema_version' => 2, 'open_composer' => ['pw' => 'getByRole']]));
    }

    public function testIsV2RejectsLegacyPlaybooks(): void
    {
        $this->assertFalse(PlaybookFormat::isV2(['schema_version' => 1, 'open_composer' => ['kind' => 'role']]));
        $this->assertFalse(PlaybookFormat::isV2(null));
    }
}
