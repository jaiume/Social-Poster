<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\SessionAccountUrls;
use PHPUnit\Framework\TestCase;

class SessionAccountUrlsTest extends TestCase
{
    public function testFacebookBootstrapUrls(): void
    {
        $this->assertSame(
            'https://www.facebook.com/',
            SessionAccountUrls::bootstrapUrl('facebook', 'root', null)
        );
        $this->assertSame(
            'https://www.facebook.com/profile.php?id=12345',
            SessionAccountUrls::bootstrapUrl('facebook', 'sub', '12345')
        );
        $this->assertSame(
            'https://www.facebook.com/WiFiVentures',
            SessionAccountUrls::bootstrapUrl('facebook', 'sub', 'WiFiVentures')
        );
    }

    public function testFacebookNormalizeSubPageLocator(): void
    {
        $this->assertSame('61565395796965', SessionAccountUrls::normalizeSubPageLocator(
            'facebook',
            'https://www.facebook.com/profile.php?id=61565395796965'
        ));
        $this->assertSame('WiFiVentures', SessionAccountUrls::normalizeSubPageLocator(
            'facebook',
            'https://www.facebook.com/WiFiVentures'
        ));
        $this->assertSame('WiFiVentures', SessionAccountUrls::normalizeSubPageLocator('facebook', 'WiFiVentures'));
        $this->assertSame('61565395796965', SessionAccountUrls::normalizeSubPageLocator('facebook', '61565395796965'));
    }

    public function testFacebookNormalizeRejectsInvalidUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionAccountUrls::normalizeSubPageLocator('facebook', 'https://www.facebook.com/groups/example');
    }

    public function testLinkedInBootstrapUrls(): void
    {
        $this->assertSame(
            'https://www.linkedin.com/feed/',
            SessionAccountUrls::bootstrapUrl('linkedin', 'root', null)
        );
        $this->assertSame(
            'https://www.linkedin.com/showcase/acme/admin/page-posts/published',
            SessionAccountUrls::bootstrapUrl('linkedin', 'sub', 'showcase/acme')
        );
        $this->assertSame(
            'https://www.linkedin.com/company/20107831/admin/page-posts/published',
            SessionAccountUrls::bootstrapUrl('linkedin', 'sub', 'company/20107831')
        );
        $this->assertSame(
            'https://www.linkedin.com/showcase/113183993/admin/page-posts/published',
            SessionAccountUrls::bootstrapUrl('linkedin', 'sub', 'showcase/113183993')
        );
        // Legacy bare slug defaults to showcase.
        $this->assertSame(
            'https://www.linkedin.com/showcase/acme/admin/page-posts/published',
            SessionAccountUrls::bootstrapUrl('linkedin', 'sub', 'acme')
        );
    }

    public function testLinkedInNormalizeFromAdminUrls(): void
    {
        $this->assertSame('company/20107831', SessionAccountUrls::normalizeSubPageLocator(
            'linkedin',
            'https://www.linkedin.com/company/20107831/admin/page-posts/published/'
        ));
        $this->assertSame('showcase/113183993', SessionAccountUrls::normalizeSubPageLocator(
            'linkedin',
            'https://www.linkedin.com/showcase/113183993/admin/page-posts/published/'
        ));
        $this->assertSame('showcase/acme', SessionAccountUrls::normalizeSubPageLocator(
            'linkedin',
            'https://www.linkedin.com/showcase/acme/admin/page-posts/published'
        ));
    }

    public function testLinkedInNormalizePrefixedInput(): void
    {
        $this->assertSame('company/20107831', SessionAccountUrls::normalizeSubPageLocator('linkedin', 'company/20107831'));
        $this->assertSame('showcase/113183993', SessionAccountUrls::normalizeSubPageLocator('linkedin', 'showcase:113183993'));
        $this->assertSame('showcase/acme', SessionAccountUrls::normalizeSubPageLocator('linkedin', 'acme'));
    }

    public function testLinkedInNormalizeRejectsBareNumericId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionAccountUrls::normalizeSubPageLocator('linkedin', '20107831');
    }

    public function testPersonalContextUrls(): void
    {
        $this->assertSame('https://www.facebook.com/', SessionAccountUrls::personalContextUrl('facebook'));
        $this->assertSame('https://www.linkedin.com/feed/', SessionAccountUrls::personalContextUrl('linkedin'));
    }

    public function testPrimaryPageBrandFromDisplayNameStripsPlatformSuffix(): void
    {
        $this->assertSame(
            'WifiVentures',
            SessionAccountUrls::primaryPageBrandFromDisplayName('linkedin', 'WifiVentures Linkedin')
        );
        $this->assertSame(
            'Acme Co',
            SessionAccountUrls::primaryPageBrandFromDisplayName('facebook', 'Acme Co Facebook')
        );
        $this->assertNull(SessionAccountUrls::primaryPageBrandFromDisplayName('linkedin', ''));
        $this->assertNull(SessionAccountUrls::primaryPageBrandFromDisplayName('linkedin', '   '));
        $this->assertSame(
            'Jamie',
            SessionAccountUrls::memberNameFromDisplayName('linkedin', 'Jamie LinkedIn')
        );
    }
}
