<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\ImageBytesValidator;
use PHPUnit\Framework\TestCase;

class ImageBytesValidatorTest extends TestCase
{
    public function testRejectsTinyPayloadAsBlank(): void
    {
        $this->assertTrue(ImageBytesValidator::isLikelyBlank(str_repeat("\0", 1000)));
    }

    public function testDetectsJpegExtension(): void
    {
        $this->assertSame('jpg', ImageBytesValidator::extensionFor("\xFF\xD8\xFF\xE0" . 'fake'));
    }
}
