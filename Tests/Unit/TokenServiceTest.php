<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Service\Storage\ArrayStorage;
use Velundo\Connect\Service\TokenService;

final class TokenServiceTest extends TestCase
{
    private ArrayStorage $storage;
    private TokenService $service;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->storage = new ArrayStorage();
        $this->service = new TokenService($this->storage, fn(): int => $this->now);
    }

    public function testCreateReturnsBase64UrlTokenAndStoresOnlyHash(): void
    {
        $created = $this->service->create('Agentur Test');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $created['token']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $created['id']);
        self::assertSame('Agentur Test', $created['name']);

        $stored = $this->storage->get(TokenService::STORAGE_KEY);
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $created['token']), $stored[$created['id']]['hash']);
        self::assertStringNotContainsString($created['token'], json_encode($stored));
    }

    public function testAuthenticateAcceptsValidBearerAndRejectsOthers(): void
    {
        $created = $this->service->create('Agentur Test');
        $result = $this->service->authenticate('Bearer ' . $created['token']);
        self::assertNotNull($result);
        self::assertSame($created['id'], $result['id']);
        self::assertSame('Agentur Test', $result['name']);

        self::assertNull($this->service->authenticate(null));
        self::assertNull($this->service->authenticate(''));
        self::assertNull($this->service->authenticate('Bearer ' . TokenService::encode(random_bytes(32))));
        self::assertNull($this->service->authenticate('Basic ' . $created['token']));
        self::assertNull($this->service->authenticate($created['token']));
    }

    public function testRevokeDisablesToken(): void
    {
        $created = $this->service->create('Agentur Test');
        self::assertTrue($this->service->revoke($created['id']));
        self::assertFalse($this->service->revoke($created['id']), 'Zweiter Widerruf schlägt fehl');
        self::assertFalse($this->service->revoke('unbekannt'));
        self::assertNull($this->service->authenticate('Bearer ' . $created['token']));
        $list = $this->service->list();
        self::assertSame($this->now, $list[0]['revoked']);
    }

    public function testListHidesHashes(): void
    {
        $this->service->create('Eins');
        $this->service->create('Zwei');
        $list = $this->service->list();
        self::assertCount(2, $list);
        foreach ($list as $entry) {
            self::assertArrayHasKey('id', $entry);
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('created', $entry);
            self::assertFalse(array_key_exists('hash', $entry));
        }
    }

    public function testLastUsedIsUpdatedAtMostOncePerMinute(): void
    {
        $created = $this->service->create('Agentur Test');
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now, $this->service->list()[0]['last_used']);
        $this->now += 30;
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now - 30, $this->service->list()[0]['last_used']);
        $this->now += 31;
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($this->now, $this->service->list()[0]['last_used']);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create('   ');
    }

    public function testExtractBearerIsStrict(): void
    {
        self::assertSame('abcdefghijklmnopqrstuvwxyz', TokenService::extractBearer('Bearer abcdefghijklmnopqrstuvwxyz'));
        self::assertSame('abcdefghijklmnopqrstuvwxyz', TokenService::extractBearer('  Bearer   abcdefghijklmnopqrstuvwxyz  '));
        self::assertNull(TokenService::extractBearer('Bearer kurz'));
        self::assertNull(TokenService::extractBearer('Bearer abc def ghijklmnopqrstuvwxyz'));
        self::assertNull(TokenService::extractBearer('Bearer abcdefghijklmnopqrstuvwxyz=='));
    }
}
