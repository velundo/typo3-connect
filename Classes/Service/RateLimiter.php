<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Velundo\Connect\Service\Storage\KeyValueStorageInterface;

/**
 * Ratenbegrenzung je Token: festes Minutenfenster, Zähler in sys_registry.
 *
 * Der Zähler wird per Compare-and-Set erhöht (Schreiben nur, wenn der gelesene Stand noch gilt; sonst neu lesen).
 * Nach ATTEMPTS vergeblichen Versuchen zählt die Anfrage mit dem zuletzt gelesenen Stand – die Grenze ist dann
 * in diesem seltenen Fall weich, aber nie strenger als konfiguriert.
 */
final class RateLimiter
{
    private const WINDOW = 60;
    private const ATTEMPTS = 3;

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
        return $this->hitKey('rate_' . $tokenId, max(1, $this->settings->rateLimitPerMinute()));
    }

    // Fehlversuche ohne gültiges Token je Absenderadresse und Minute – Raten von Tokens ist damit schon vor der Token-Prüfung begrenzt
    public const AUTH_FAILURES_PER_MINUTE = 30;

    /**
     * Zählt einen Fehlversuch (kein gültiges Token) der Absenderadresse; ab AUTH_FAILURES_PER_MINUTE antwortet die
     * Schnittstelle mit 429 statt 401.
     *
     * @return array{allowed: bool, limit: int, remaining: int, retry_after: int}
     */
    public function hitAuthFailure(string $remoteAddress): array
    {
        return $this->hitKey('auth_' . substr(hash('sha256', $remoteAddress), 0, 32), self::AUTH_FAILURES_PER_MINUTE);
    }

    /** @return array{allowed: bool, limit: int, remaining: int, retry_after: int} */
    private function hitKey(string $key, int $limit): array
    {
        $now = ($this->clock)();
        $window = intdiv($now, self::WINDOW);
        $count = 1;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $current = $this->storage->get($key);
            $sameWindow = is_array($current) && (int)($current['window'] ?? 0) === $window;
            $count = ($sameWindow ? (int)($current['count'] ?? 0) : 0) + 1;
            if ($this->storage->compareAndSet($key, is_array($current) ? $current : null, ['window' => $window, 'count' => $count])) {
                break;
            }
        }
        $retryAfter = ($window + 1) * self::WINDOW - $now;
        return [
            'allowed' => $count <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => max(1, $retryAfter),
        ];
    }
}
