<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Velundo\Connect\Service\Storage\KeyValueStorageInterface;

/**
 * Zugangstokens: 32 zufällige Bytes als base64url, gespeichert nur als SHA-256-Hash in sys_registry.
 * Mehrere Tokens mit Bezeichnung, widerrufbar; Vergleich in konstanter Zeit.
 *
 * Die Token-Metadaten (Schlüssel `tokens`) schreiben nur create() und revoke() – beides Kommandozeile.
 * authenticate() schreibt ausschließlich den eigenen Schlüssel `last_used_<id>`, damit eine Anfrage
 * nie einen parallel ausgeführten Widerruf oder ein gerade erzeugtes Token überschreiben kann.
 */
final class TokenService
{
    public const STORAGE_KEY = 'tokens';
    public const LAST_USED_PREFIX = 'last_used_';
    private const LAST_USED_INTERVAL = 60;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly KeyValueStorageInterface $storage,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Erzeugt ein Token; der Klartext wird nur hier zurückgegeben.
     *
     * @return array{id: string, name: string, token: string}
     */
    public function create(string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Die Bezeichnung muss 1 bis 100 Zeichen lang sein.');
        }
        $token = self::encode(random_bytes(32));
        $tokens = $this->all();
        do {
            $id = bin2hex(random_bytes(4));
        } while (isset($tokens[$id]));
        $tokens[$id] = [
            'name' => $name,
            'hash' => self::hash($token),
            'created' => ($this->clock)(),
            'revoked' => null,
        ];
        $this->storage->set(self::STORAGE_KEY, $tokens);
        return ['id' => $id, 'name' => $name, 'token' => $token];
    }

    /**
     * Alle Tokens ohne Hash (auch widerrufene).
     *
     * @return list<array{id: string, name: string, created: int, last_used: ?int, revoked: ?int}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->all() as $id => $record) {
            $result[] = [
                'id' => (string)$id,
                'name' => (string)$record['name'],
                'created' => (int)$record['created'],
                'last_used' => $this->lastUsed((string)$id, $record),
                'revoked' => $record['revoked'] === null ? null : (int)$record['revoked'],
            ];
        }
        return $result;
    }

    public function revoke(string $id): bool
    {
        $tokens = $this->all();
        if (!isset($tokens[$id]) || $tokens[$id]['revoked'] !== null) {
            return false;
        }
        $tokens[$id]['revoked'] = ($this->clock)();
        $this->storage->set(self::STORAGE_KEY, $tokens);
        return true;
    }

    /**
     * Prüft ein Token aus dem Authorization-Header; liefert id und Bezeichnung oder null.
     *
     * @return array{id: string, name: string}|null
     */
    public function authenticate(?string $authorizationHeader): ?array
    {
        $token = self::extractBearer($authorizationHeader);
        if ($token === null) {
            return null;
        }
        $hash = self::hash($token);
        $tokens = $this->all();
        $match = null;
        // Alle Einträge vergleichen, damit die Laufzeit nicht von der Position des Treffers abhängt.
        foreach ($tokens as $id => $record) {
            $equal = hash_equals((string)($record['hash'] ?? ''), $hash);
            if ($equal && $record['revoked'] === null && $match === null) {
                $match = (string)$id;
            }
        }
        if ($match === null) {
            return null;
        }
        $now = ($this->clock)();
        $lastUsed = $this->lastUsed($match, $tokens[$match]);
        if ($lastUsed === null || $now - $lastUsed >= self::LAST_USED_INTERVAL) {
            // Eigener Schlüssel je Token – die Token-Liste selbst wird hier nie zurückgeschrieben.
            $this->storage->set(self::LAST_USED_PREFIX . $match, $now);
        }
        return ['id' => $match, 'name' => (string)$tokens[$match]['name']];
    }

    public static function extractBearer(?string $header): ?string
    {
        if ($header === null || preg_match('/^\s*Bearer\s+([A-Za-z0-9_-]{20,})\s*$/', $header, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Zeitpunkt der letzten Verwendung: eigener Schlüssel, ersatzweise das Feld aus älteren Ständen (1.0.0).
     *
     * @param array<string, mixed> $record
     */
    private function lastUsed(string $id, array $record): ?int
    {
        $value = $this->storage->get(self::LAST_USED_PREFIX . $id);
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        $legacy = $record['last_used'] ?? null;
        return $legacy === null ? null : (int)$legacy;
    }

    /** @return array<string, array{name: string, hash: string, created: int, revoked: ?int, last_used?: ?int}> */
    private function all(): array
    {
        $tokens = $this->storage->get(self::STORAGE_KEY, []);
        return is_array($tokens) ? $tokens : [];
    }
}
