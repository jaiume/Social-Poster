<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\DAO\BrowserSessionDao;
use App\Services\BrowserSessionService;
use App\Services\ConfigService;
use App\Services\EncryptionService;
use App\Services\SessionAccountService;
use PHPUnit\Framework\TestCase;

class BrowserSessionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigService::reset();
    }

    public function testCreateSessionRequiresUniqueName(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findByName')->with('Jamie Facebook')->willReturn(['id' => 1]);
        $service = new BrowserSessionService(
            $dao,
            $this->createMock(SessionAccountService::class),
            new EncryptionService()
        );

        $result = $service->createSession('Jamie Facebook', 'facebook');

        $this->assertFalse($result['success']);
        $this->assertSame('VALIDATION_ERROR', $result['error']['code']);
    }

    public function testImportSessionActivatesPendingSession(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findById')->with(2)->willReturn([
            'id' => 2,
            'platform' => 'linkedin',
            'status' => 'pending',
        ]);
        $dao->expects($this->once())->method('updateStorage')->with(
            2,
            $this->isType('string'),
            'active'
        );

        $service = new BrowserSessionService(
            $dao,
            $this->createMock(SessionAccountService::class),
            new EncryptionService()
        );
        $result = $service->importSession(2, '{"cookies":[]}');

        $this->assertTrue($result['success']);
    }

    public function testWriteDecryptedToTempRejectsNonActiveSession(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findById')->with(3)->willReturn([
            'id' => 3,
            'platform' => 'facebook',
            'status' => 'pending',
            'storage_state' => 'x',
        ]);

        $service = new BrowserSessionService(
            $dao,
            $this->createMock(SessionAccountService::class),
            new EncryptionService()
        );

        $this->assertNull($service->writeDecryptedToTemp(3));
    }

    public function testDeleteSessionBlocksWhenProfileReferencesExist(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findById')->with(4)->willReturn([
            'id' => 4,
            'platform' => 'linkedin',
            'status' => 'expired',
        ]);
        $dao->method('countProfileReferences')->with(4)->willReturn(2);
        $dao->expects($this->never())->method('delete');

        $service = new BrowserSessionService(
            $dao,
            $this->createMock(SessionAccountService::class),
            new EncryptionService()
        );
        $result = $service->deleteSession(4);

        $this->assertFalse($result['success']);
        $this->assertSame('IN_USE', $result['error']['code']);
    }

    public function testDeleteSessionSucceedsWhenUnreferenced(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findById')->with(4)->willReturn([
            'id' => 4,
            'platform' => 'linkedin',
            'status' => 'expired',
        ]);
        $dao->method('countProfileReferences')->with(4)->willReturn(0);
        $dao->expects($this->once())->method('delete')->with(4);

        $service = new BrowserSessionService(
            $dao,
            $this->createMock(SessionAccountService::class),
            new EncryptionService()
        );
        $result = $service->deleteSession(4);

        $this->assertTrue($result['success']);
    }

    public function testRenameSessionSyncsRootAccountDisplayName(): void
    {
        $dao = $this->createMock(BrowserSessionDao::class);
        $dao->method('findById')->with(5)->willReturn(['id' => 5, 'name' => 'Old']);
        $dao->method('findByName')->with('New Name')->willReturn(null);
        $dao->expects($this->once())->method('updateName')->with(5, 'New Name');

        $accounts = $this->createMock(SessionAccountService::class);
        $accounts->expects($this->once())->method('syncRootName')->with(5, 'New Name');

        $service = new BrowserSessionService($dao, $accounts, new EncryptionService());
        $result = $service->renameSession(5, 'New Name');

        $this->assertTrue($result['success']);
    }
}
