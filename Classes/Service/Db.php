<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;

/**
 * Lesezugriffe über den QueryBuilder: gelöschte Datensätze nie, versteckte je nach ?hidden=.
 */
final class Db
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /** QueryBuilder für eine Tabelle ohne gelöschte Datensätze (weitere Bedingungen setzt der Aufrufer). */
    public function query(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder;
    }

    /** Fügt die Sichtbarkeitsbedingung für ?hidden= hinzu (Feld `hidden` der Tabelle). */
    public function applyHidden(QueryBuilder $queryBuilder, string $mode, string $field = 'hidden'): void
    {
        if ($mode === ApiRequest::HIDDEN_VISIBLE) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($field, 0));
        } elseif ($mode === ApiRequest::HIDDEN_HIDDEN) {
            $queryBuilder->andWhere($queryBuilder->expr()->neq($field, 0));
        }
    }

    /**
     * Ein Datensatz (nicht gelöscht) oder 404.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function record(string $table, int $uid, array $fields = ['*'], string $label = 'Datensatz'): array
    {
        $queryBuilder = $this->query($table);
        $row = $queryBuilder->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            throw ApiException::notFound(sprintf('%s %d wurde nicht gefunden.', $label, $uid), 'record_not_found', ['uid' => $uid]);
        }
        return $row;
    }

    /** LIKE-Muster für eine Suche „enthält“ (Platzhalter des Suchtexts maskiert). */
    public function contains(QueryBuilder $queryBuilder, string $term): string
    {
        return $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($term) . '%');
    }

    /** Zeitstempel als ISO 8601 mit Versatz (Serverzeitzone); 0 ergibt null. */
    public static function iso(int|string|null $timestamp): ?string
    {
        $timestamp = (int)$timestamp;
        if ($timestamp <= 0) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format(DATE_ATOM);
    }
}
