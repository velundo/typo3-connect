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
}
