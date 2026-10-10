<?php

declare(strict_types=1);

namespace Velundo\Connect\Service\Storage;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ablage in sys_registry, Namensraum velundo_connect – im selben Format wie TYPO3\CMS\Core\Registry
 * (serialisierter Wert in entry_value), aber ohne dessen Zwischenspeicher je Anfrage, damit compareAndSet()
 * immer den aktuellen Stand der Datenbank sieht.
 */
final class RegistryStorage implements KeyValueStorageInterface
{
    public const NAMESPACE = 'velundo_connect';
    private const TABLE = 'sys_registry';

    public function get(string $key, mixed $default = null): mixed
    {
        $serialized = $this->read($key);
        return $serialized === null ? $default : self::decode($serialized);
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->read($key) === null) {
            try {
                $this->insert($key, $value);
                return;
            } catch (\Throwable) {
                // Zeitgleich angelegt – dann aktualisieren
            }
        }
        $this->connection()->update(
            self::TABLE,
            ['entry_value' => serialize($value)],
            ['entry_namespace' => self::NAMESPACE, 'entry_key' => $key],
            [Connection::PARAM_LOB, Connection::PARAM_STR, Connection::PARAM_STR]
        );
    }

    public function remove(string $key): void
    {
        $this->connection()->delete(self::TABLE, ['entry_namespace' => self::NAMESPACE, 'entry_key' => $key]);
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value): bool
    {
        if ($expected === null) {
            try {
                $this->insert($key, $value);
                return true;
            } catch (\Throwable) {
                // Eindeutiger Schlüssel verletzt: jemand war schneller
                return false;
            }
        }
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $affected = $queryBuilder->update(self::TABLE)
            ->set('entry_value', $queryBuilder->createNamedParameter(serialize($value), Connection::PARAM_LOB), false)
            ->where(
                $queryBuilder->expr()->eq('entry_namespace', $queryBuilder->createNamedParameter(self::NAMESPACE)),
                $queryBuilder->expr()->eq('entry_key', $queryBuilder->createNamedParameter($key)),
                $queryBuilder->expr()->eq('entry_value', $queryBuilder->createNamedParameter(serialize($expected), Connection::PARAM_LOB))
            )
            ->executeStatement();
        return (int)$affected === 1;
    }

    private function insert(string $key, mixed $value): void
    {
        $this->connection()->insert(
            self::TABLE,
            ['entry_namespace' => self::NAMESPACE, 'entry_key' => $key, 'entry_value' => serialize($value)],
            [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_LOB]
        );
    }

    /** Serialisierter Wert oder null, wenn es den Eintrag nicht gibt. */
    private function read(string $key): ?string
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $raw = $queryBuilder->select('entry_value')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('entry_namespace', $queryBuilder->createNamedParameter(self::NAMESPACE)),
                $queryBuilder->expr()->eq('entry_key', $queryBuilder->createNamedParameter($key))
            )
            ->executeQuery()
            ->fetchOne();
        if ($raw === false || $raw === null) {
            return null;
        }
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }
        return (string)$raw;
    }

    private static function decode(string $serialized): mixed
    {
        return unserialize($serialized, ['allowed_classes' => false]);
    }

    private function connection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
    }
}
