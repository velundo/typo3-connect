<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Service\Storage\ArrayStorage;
use Velundo\Connect\Service\Storage\KeyValueStorageInterface;
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

    public function testAuthenticateNeverWritesTheTokenList(): void
    {
        $created = $this->service->create('Agentur Test');
        $snapshot = $this->storage->get(TokenService::STORAGE_KEY);
        $this->service->authenticate('Bearer ' . $created['token']);
        $this->now += 120;
        $this->service->authenticate('Bearer ' . $created['token']);
        self::assertSame($snapshot, $this->storage->get(TokenService::STORAGE_KEY), 'authenticate() darf die Token-Liste nicht zurückschreiben');
        self::assertSame($this->now, $this->storage->get(TokenService::LAST_USED_PREFIX . $created['id']));
    }

    public function testRevokeDuringAuthenticateIsNotLost(): void
    {
        // Review Nr. 1 (Lost Update): Zwischen dem Lesen der Token-Liste in authenticate() und dem Schreiben
        // von last_used widerruft die Kommandozeile das Token. Der Widerruf muss erhalten bleiben.
        $inner = $this->storage;
        $cli = new TokenService($inner, fn(): int => $this->now);
        $created = $cli->create('Agentur Test');
        $second = $cli->create('Zweites Token');

        $racing = new class ($inner, $cli, $created['id']) implements KeyValueStorageInterface {
            private bool $armed = true;

            public function __construct(
                private readonly KeyValueStorageInterface $inner,
                private readonly TokenService $cli,
                private readonly string $revokeId,
            ) {}

            public function get(string $key, mixed $default = null): mixed
            {
                $value = $this->inner->get($key, $default);
                if ($key === TokenService::STORAGE_KEY && $this->armed) {
                    // Nach dem Lesen, vor dem Schreiben: Widerruf durch einen anderen Prozess
                    $this->armed = false;
                    $this->cli->revoke($this->revokeId);
                }
                return $value;
            }

            public function set(string $key, mixed $value): void
            {
                $this->inner->set($key, $value);
            }

            public function remove(string $key): void
            {
                $this->inner->remove($key);
            }

            public function compareAndSet(string $key, mixed $expected, mixed $value): bool
            {
                return $this->inner->compareAndSet($key, $expected, $value);
            }
        };
        $web = new TokenService($racing, fn(): int => $this->now);
        $result = $web->authenticate('Bearer ' . $created['token']);
        self::assertNotNull($result, 'Die Anfrage selbst hatte das Token noch gültig gelesen');

        // Der Widerruf ist erhalten, das zweite Token ebenfalls; last_used wurde trotzdem vermerkt
        $list = $cli->list();
        self::assertCount(2, $list);
        self::assertSame($this->now, $list[0]['revoked']);
        self::assertSame($this->now, $list[0]['last_used']);
        self::assertSame($second['id'], $list[1]['id']);
        self::assertNull($cli->authenticate('Bearer ' . $created['token']), 'Widerrufenes Token wird abgelehnt');
    }

    public function testLastUsedFromOlderRecordsIsStillShown(): void
    {
        // Stand 1.0.0 speicherte last_used im Token-Datensatz selbst
        $this->storage->set(TokenService::STORAGE_KEY, [
            'abcd1234' => ['name' => 'Alt', 'hash' => str_repeat('0', 64), 'created' => 1, 'last_used' => 42, 'revoked' => null],
        ]);
        self::assertSame(42, $this->service->list()[0]['last_used']);
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
