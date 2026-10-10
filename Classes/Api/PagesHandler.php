<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Site\Entity\Site;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\ChangeSet;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Http\Validate;
use Velundo\Connect\Service\DataHandlerService;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\LogService;
use Velundo\Connect\Service\PageTree;
use Velundo\Connect\Service\SiteResolver;

/**
 * pages – Seitenbaum lesen, einzelne Seite lesen und Felder ändern.
 */
final class PagesHandler implements HandlerInterface
{
    public const WRITABLE = ['title', 'nav_title', 'seo_title', 'description', 'no_index', 'no_follow', 'hidden', 'canonical'];
    private const SEO_WRITABLE = ['seo_title', 'no_index', 'no_follow', 'canonical'];
    private const DEPTH_MAX = 5;

    /** Höchstlängen je Feld (Spaltenbreite in der Datenbank). */
    private const MAX_LENGTH = ['title' => 255, 'nav_title' => 255, 'seo_title' => 255, 'description' => 5000, 'canonical' => 2048];

    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
        private readonly SiteResolver $sites,
        private readonly Features $features,
        private readonly DataHandlerService $writer,
        private readonly LogService $log,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->detail((int)$request->uid, $request->hiddenMode())),
            'update' => $this->update($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $site = $this->sites->resolve($request);
        $hiddenMode = $request->hiddenMode();
        $depth = $request->int('depth', 1, 1, self::DEPTH_MAX);
        $search = mb_strtolower($request->string('search'));
        $parent = $request->int('parent', 0, 1);
        if ($parent > 0) {
            // Übersetzungs-uid auf die Standardseite auflösen; die Site ist die der Seite, nicht die aus ?site=
            $parent = self::defaultUid($this->db->record('pages', $parent, ['uid', 'sys_language_uid', 'l10n_parent'], 'Seite'));
            $site = $this->sites->siteForPage($parent, $site);
        }
        $languageId = $this->sites->language($site, $request->languageId())->getLanguageId();
        // Bei Übersetzungen entscheidet die Sichtbarkeit der Übersetzungszeile (localize), nicht die der Standardseite
        $defaultHiddenMode = $languageId > 0 ? ApiRequest::HIDDEN_ALL : $hiddenMode;

        if ($parent > 0) {
            $rows = $this->tree->subtree($parent, $depth, $defaultHiddenMode);
        } else {
            $root = $site->getRootPageId();
            $rootRow = $this->db->record('pages', $root, $this->tree->columns(), 'Seite');
            $rows = $this->tree->subtree($root, $depth, $defaultHiddenMode, $rootRow);
        }
        $rows = $this->tree->localize($rows, $languageId, $hiddenMode);

        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                foreach (['title', 'nav_title', 'slug', 'seo_title'] as $field) {
                    if (isset($row[$field]) && str_contains(mb_strtolower((string)$row[$field]), $search)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $total = count($rows);
        $limit = $request->limit();
        $offset = $request->offset();
        $slice = array_slice($rows, $offset, $limit);
        $counts = $this->tree->contentCounts(array_map([self::class, 'defaultUid'], $slice), $languageId, $hiddenMode);

        $items = [];
        foreach ($slice as $row) {
            $items[] = $this->format($row, $site, ($counts[self::defaultUid($row)] ?? 0) > 0);
        }
        return Result::page($items, $total, $limit, $offset);
    }

    /**
     * Seite wie GET pages/{uid}: Felder plus content_summary und die weiteren SEO-Felder.
     * `has_content` folgt dem Sichtbarkeitsmodus (Standard: nur sichtbare Elemente zählen); content_summary nennt alle.
     *
     * @return array<string, mixed>
     */
    public function detail(int $uid, string $hiddenMode = ApiRequest::HIDDEN_VISIBLE): array
    {
        $row = $this->db->record('pages', $uid, $this->tree->columns(), 'Seite');
        $defaultUid = self::defaultUid($row);
        $site = $this->sites->siteForPage($defaultUid);

        $queryBuilder = $this->db->query('tt_content');
        $summaryRows = $queryBuilder->select('uid', 'colPos', 'CType', 'header', 'hidden')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($defaultUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter((int)$row['sys_language_uid'], Connection::PARAM_INT))
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
        $summary = [];
        foreach ($summaryRows as $content) {
            $summary[] = [
                'uid' => (int)$content['uid'],
                'colPos' => (int)$content['colPos'],
                'CType' => (string)$content['CType'],
                'header' => (string)$content['header'],
                'hidden' => (bool)$content['hidden'],
            ];
        }

        $seo = $this->features->seo();
        $page = $this->format($row, $site, PageTree::filterHidden($summary, $hiddenMode) !== []);
        $page['content_summary'] = $summary;
        $page['og_title'] = $seo ? (string)($row['og_title'] ?? '') : null;
        $page['og_description'] = $seo ? (string)($row['og_description'] ?? '') : null;
        $page['twitter_title'] = $seo ? (string)($row['twitter_title'] ?? '') : null;
        $page['twitter_description'] = $seo ? (string)($row['twitter_description'] ?? '') : null;
        $page['sitemap_changefreq'] = $seo ? (string)($row['sitemap_changefreq'] ?? '') : null;
        $page['sitemap_priority'] = $seo ? (float)($row['sitemap_priority'] ?? 0.5) : null;
        return $page;
    }

    private function update(ApiRequest $request): Result
    {
        $uid = (int)$request->uid;
        $row = $this->db->record('pages', $uid, $this->tree->columns(), 'Seite');
        $body = $request->fields(self::WRITABLE);
        if (!$this->features->seo() && array_intersect(array_keys($body), self::SEO_WRITABLE) !== []) {
            throw ApiException::featureUnavailable('seo');
        }

        $changes = new ChangeSet();
        $current = $this->format($row, null, false);
        $map = ['title' => 'title', 'nav_title' => 'nav_title', 'seo_title' => 'seo_title', 'description' => 'description', 'canonical' => 'canonical_link'];
        foreach ($map as $apiField => $dbField) {
            $changes->set($apiField, Validate::string($body, $apiField, self::MAX_LENGTH[$apiField]), $current[$apiField], $dbField);
        }
        foreach (['no_index', 'no_follow', 'hidden'] as $flag) {
            $changes->flag($flag, Validate::bool($body, $flag), (bool)$current[$flag]);
        }
        $changes->requireAny(self::WRITABLE);

        $this->writer->update('pages', $uid, $changes->data());
        $this->log->log(sprintf('Seite %d geändert (%s)', $uid, $changes->fieldList()), $request->tokenName, 'pages', $uid, (int)$row['pid']);

        $page = $this->detail($uid, $request->hiddenMode());
        $page['before'] = $changes->before();
        return Result::ok($page);
    }

    /**
     * Felder einer Seite nach Vertrag (Listenform).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function format(array $row, ?Site $site, bool $hasContent): array
    {
        $seo = $this->features->seo();
        $languageId = (int)$row['sys_language_uid'];
        $page = [
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'title' => (string)$row['title'],
            'nav_title' => (string)$row['nav_title'],
            'slug' => (string)$row['slug'],
            'url' => $this->sites->pageUrl($site, $row),
            'doktype' => (int)$row['doktype'],
            'hidden' => (bool)$row['hidden'],
            'nav_hide' => (bool)$row['nav_hide'],
            'no_index' => $seo ? (bool)($row['no_index'] ?? 0) : null,
            'no_follow' => $seo ? (bool)($row['no_follow'] ?? 0) : null,
            'seo_title' => $seo ? (string)($row['seo_title'] ?? '') : null,
            'description' => (string)$row['description'],
            'canonical' => $seo ? (string)($row['canonical_link'] ?? '') : null,
            'tstamp' => Db::iso($row['tstamp']),
            'crdate' => Db::iso($row['crdate']),
            'language' => $languageId,
            'has_content' => $hasContent,
        ];
        if ($languageId > 0) {
            $page['l10n_parent'] = (int)$row['l10n_parent'];
        }
        return $page;
    }

    /** uid der Standardsprachseite (bei Übersetzungen l10n_parent). */
    public static function defaultUid(array $row): int
    {
        return (int)$row['sys_language_uid'] > 0 && (int)$row['l10n_parent'] > 0 ? (int)$row['l10n_parent'] : (int)$row['uid'];
    }
}
