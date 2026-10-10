<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Velundo\Connect\Service\Storage\KeyValueStorageInterface;

/**
 * Ratenbegrenzung je Token: festes Minutenfenster, Zähler in sys_registry.
 */
final class RateLimiter
{
    private const WINDOW = 60;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly KeyValueStorageInterface $storage,
        private readonly Settings $settings,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Zählt eine Anfrage und sagt, ob sie noch erlaubt ist.
     *
     * @return array{allowed: bool, limit: int, remaining: int, retry_after: int}
     */
    public function hit(string $tokenId): array
    {
        $limit = max(1, $this->settings->rateLimitPerMinute());
        $now = ($this->clock)();
        $window = intdiv($now, self::WINDOW);
        $key = 'rate_' . $tokenId;
        $state = $this->storage->get($key);
        if (!is_array($state) || (int)($state['window'] ?? 0) !== $window) {
            $state = ['window' => $window, 'count' => 0];
        }
        $state['count'] = (int)$state['count'] + 1;
        $this->storage->set($key, $state);
        $retryAfter = ($window + 1) * self::WINDOW - $now;
        return [
            'allowed' => $state['count'] <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $state['count']),
            'retry_after' => max(1, $retryAfter),
        ];
    }
}
