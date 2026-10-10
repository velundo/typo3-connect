<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Database\Connection;
use Velundo\Connect\Http\ApiRequest;

/**
 * Seitenbaum lesen: Kinder je Ebene in einer Abfrage, Übersetzungen als Overlay, Tiefensuche in Sortierreihenfolge.
 */
final class PageTree
{
    public const MAX_LEVELS = 20;

    /** Spalten des Kerns; SEO-Spalten kommen aus EXT:seo und werden nur gelesen, wenn sie in der TCA stehen. */
    private const BASE_COLUMNS = ['uid', 'pid', 'title', 'nav_title', 'slug', 'doktype', 'hidden', 'nav_hide', 'description', 'tstamp', 'crdate', 'sys_language_uid', 'l10n_parent', 'sorting'];
    private const SEO_COLUMNS = ['seo_title', 'no_index', 'no_follow', 'canonical_link', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'sitemap_changefreq', 'sitemap_priority'];

    /** Papierkorb und Trennlinien haben keine Adresse und werden nie gelistet. */
    private const EXCLUDED_DOKTYPES = [199, 255];

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(private readonly Db $db) {}

    /** @return list<string> */
    public function columns(): array
    {
        if ($this->columns === null) {
            $this->columns = self::BASE_COLUMNS;
            foreach (self::SEO_COLUMNS as $column) {
                if (isset($GLOBALS['TCA']['pages']['columns'][$column])) {
                    $this->columns[] = $column;
                }
            }
        }
        return $this->columns;
    }

    /**
     * Kinder (Standardsprache) der übergebenen Seiten, gruppiert nach pid und in Sortierreihenfolge.
     *
     * @param list<int> $parentUids
     * @return array<int, list<array<string, mixed>>>
     */
    public function children(array $parentUids, string $hiddenMode): array
    {
        if ($parentUids === []) {
            return [];
        }
        $queryBuilder = $this->db->query('pages');
        $queryBuilder->select(...$this->columns())
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->notIn('doktype', $queryBuilder->createNamedParameter(self::EXCLUDED_DOKTYPES, Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('sorting')
            ->addOrderBy('uid');
        $this->db->applyHidden($queryBuilder, $hiddenMode);
        $grouped = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $grouped[(int)$row['pid']][] = $row;
        }
        return $grouped;
    }

    /**
     * Teilbaum in Tiefensuche (Standardsprache). $depth = Zahl der Ebenen unter $startUid.
     * Der Baum wird immer vollständig geladen (sonst fehlten sichtbare Unterseiten versteckter Seiten);
     * der Sichtbarkeitsfilter gilt erst für die Ergebniszeilen – auch für $startRow.
     *
     * @param array<string, mixed>|null $startRow Zeile der Startseite, wenn sie selbst enthalten sein soll
     * @return list<array<string, mixed>>
     */
    public function subtree(int $startUid, int $depth, string $hiddenMode, ?array $startRow = null): array
    {
        $depth = max(0, min(self::MAX_LEVELS, $depth));
        $childrenByPid = [];
        $level = [$startUid];
        for ($i = 0; $i < $depth && $level !== []; $i++) {
            $grouped = $this->children($level, ApiRequest::HIDDEN_ALL);
            $level = [];
            foreach ($grouped as $pid => $rows) {
                $childrenByPid[$pid] = $rows;
                foreach ($rows as $row) {
                    $level[] = (int)$row['uid'];
                }
            }
        }
        $result = $startRow !== null ? [$startRow] : [];
        $this->walk($startUid, $childrenByPid, $result);
        return self::filterHidden($result, $hiddenMode);
    }

    /**
     * Sichtbarkeitsfilter auf geladene Zeilen (Feld `hidden`).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filterHidden(array $rows, string $hiddenMode): array
    {
        if ($hiddenMode === ApiRequest::HIDDEN_ALL) {
            return $rows;
        }
        $wantHidden = $hiddenMode === ApiRequest::HIDDEN_HIDDEN;
        return array_values(array_filter($rows, static fn(array $row): bool => (bool)($row['hidden'] ?? false) === $wantHidden));
    }

    /**
     * Übersetzungen (Overlay-Zeilen) zu Standardseiten, Schlüssel ist die uid der Standardseite.
     *
     * @param list<int> $defaultUids
     * @return array<int, array<string, mixed>>
     */
    public function translations(array $defaultUids, int $languageId, string $hiddenMode): array
    {
        if ($defaultUids === [] || $languageId <= 0) {
            return [];
        }
        $result = [];
        foreach (array_chunk($defaultUids, 500) as $chunk) {
            $queryBuilder = $this->db->query('pages');
            $queryBuilder->select(...$this->columns())
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT))
                )
                ->orderBy('uid');
            $this->db->applyHidden($queryBuilder, $hiddenMode);
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $result[(int)$row['l10n_parent']] ??= $row;
            }
        }
        return $result;
    }

    /**
     * Zeilen in der gewünschten Sprache: Standardsprache unverändert, sonst Übersetzungen in gleicher Reihenfolge
     * (Seiten ohne Übersetzung entfallen).
     *
     * @param list<array<string, mixed>> $defaultRows
     * @return list<array<string, mixed>>
     */
    public function localize(array $defaultRows, int $languageId, string $hiddenMode): array
    {
        if ($languageId <= 0) {
            return $defaultRows;
        }
        $translations = $this->translations(array_map(static fn(array $row): int => (int)$row['uid'], $defaultRows), $languageId, $hiddenMode);
        $result = [];
        foreach ($defaultRows as $row) {
            if (isset($translations[(int)$row['uid']])) {
                $result[] = $translations[(int)$row['uid']];
            }
        }
        return $result;
    }

    /**
     * Anzahl Inhaltselemente je Seite (Standard-uid) in einer Sprache.
     *
     * @param list<int> $pageUids
     * @return array<int, int>
     */
    public function contentCounts(array $pageUids, int $languageId, string $hiddenMode): array
    {
        if ($pageUids === []) {
            return [];
        }
        $counts = [];
        foreach (array_chunk($pageUids, 500) as $chunk) {
            $queryBuilder = $this->db->query('tt_content');
            $queryBuilder->selectLiteral('pid, COUNT(*) AS cnt')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT))
                )
                ->groupBy('pid');
            $this->db->applyHidden($queryBuilder, $hiddenMode);
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $counts[(int)$row['pid']] = (int)$row['cnt'];
            }
        }
        return $counts;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $childrenByPid
     * @param list<array<string, mixed>> $result
     */
    private function walk(int $uid, array $childrenByPid, array &$result): void
    {
        foreach ($childrenByPid[$uid] ?? [] as $row) {
            $result[] = $row;
            $this->walk((int)$row['uid'], $childrenByPid, $result);
        }
    }
}
