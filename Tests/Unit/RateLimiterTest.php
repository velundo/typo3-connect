<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Service\RateLimiter;
use Velundo\Connect\Service\Settings;
use Velundo\Connect\Service\Storage\ArrayStorage;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_800_000_010;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new RateLimiter(new ArrayStorage(), new Settings(['rate_limit_per_minute' => 3]), fn(): int => $this->now);
    }

    public function testLimitWithinWindow(): void
    {
        self::assertTrue($this->limiter->hit('a')['allowed']);
        self::assertTrue($this->limiter->hit('a')['allowed']);
        $third = $this->limiter->hit('a');
        self::assertTrue($third['allowed']);
        self::assertSame(0, $third['remaining']);
        $fourth = $this->limiter->hit('a');
        self::assertFalse($fourth['allowed']);
        self::assertSame(3, $fourth['limit']);
        self::assertGreaterThan(0, $fourth['retry_after']);
        self::assertLessThanOrEqual(60, $fourth['retry_after']);
        // Zweites Token hat ein eigenes Kontingent
        self::assertTrue($this->limiter->hit('b')['allowed']);
    }

    public function testWindowResets(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->limiter->hit('a');
        }
        self::assertFalse($this->limiter->hit('a')['allowed']);
        $this->now += 60;
        self::assertTrue($this->limiter->hit('a')['allowed']);
    }

    public function testRetryAfterPointsToNextWindow(): void
    {
        // now = …010 → Fenster endet bei …060, also 50 Sekunden
        $hit = $this->limiter->hit('a');
        self::assertSame(50, $hit['retry_after']);
    }

    public function testDefaultLimitIs120(): void
    {
        $limiter = new RateLimiter(new ArrayStorage(), new Settings([]), fn(): int => $this->now);
        self::assertSame(120, $limiter->hit('x')['limit']);
    }

    public function testArrayStorageCompareAndSet(): void
    {
        $storage = new ArrayStorage();
        self::assertTrue($storage->compareAndSet('k', null, ['a' => 1]));
        self::assertFalse($storage->compareAndSet('k', null, ['a' => 2]), 'Eintrag existiert bereits');
        self::assertFalse($storage->compareAndSet('k', ['a' => 9], ['a' => 2]), 'Erwartungswert stimmt nicht');
        self::assertTrue($storage->compareAndSet('k', ['a' => 1], ['a' => 2]));
        self::assertSame(['a' => 2], $storage->get('k'));
    }

    public function testConcurrentHitIsCountedAfterRetry(): void
    {
        // Zwischen Lesen und Schreiben zählt ein anderer Prozess – der Zähler darf den Treffer nicht verlieren
        $storage = new class extends ArrayStorageDecorator {
            public int $interferences = 0;

            public function compareAndSet(string $key, mixed $expected, mixed $value): bool
            {
                if ($this->interferences === 0) {
                    $this->interferences++;
                    // Fremder Prozess schreibt zuerst (gleiches Fenster, ein Treffer mehr)
                    $foreign = is_array($expected) ? $expected : ['window' => $value['window'], 'count' => 0];
                    $foreign['count'] = (int)$foreign['count'] + 1;
                    $this->inner->set($key, $foreign);
                }
                return $this->inner->compareAndSet($key, $expected, $value);
            }
        };
        $limiter = new RateLimiter($storage, new Settings(['rate_limit_per_minute' => 3]), fn(): int => $this->now);
        $hit = $limiter->hit('a');
        self::assertTrue($hit['allowed']);
        self::assertSame(1, $storage->interferences);
        self::assertSame(2, $storage->get('rate_a')['count'], 'Fremder Treffer plus eigener Treffer');
        self::assertSame(1, $hit['remaining']);
    }
}

/** Reicht alle Aufrufe an einen ArrayStorage durch; Prüfungen überschreiben einzelne Methoden. */
abstract class ArrayStorageDecorator implements \Velundo\Connect\Service\Storage\KeyValueStorageInterface
{
    protected ArrayStorage $inner;

    public function __construct()
    {
        $this->inner = new ArrayStorage();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
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
}
