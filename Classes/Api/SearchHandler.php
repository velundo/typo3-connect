<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Database\Connection;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\HtmlSanitizer;
use Velundo\Connect\Service\PageTree;
use Velundo\Connect\Service\SiteResolver;

/**
 * GET search?q= – Seiten (Titel), Inhaltselemente (Header, Bodytext) und Nachrichten mit Treffer innerhalb einer Site.
 */
final class SearchHandler implements HandlerInterface
{
    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
        private readonly SiteResolver $sites,
        private readonly Features $features,
        private readonly PagesHandler $pages,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $term = $request->string('q');
        if (mb_strlen($term) < 2) {
            throw ApiException::validation('Parameter „q“ muss mindestens zwei Zeichen lang sein.', ['parameter' => 'q']);
        }
        $site = $this->sites->resolve($request);
        $languageId = $this->sites->language($site, $request->languageId())->getLanguageId();
        $hiddenMode = $request->hiddenMode();
        $limit = $request->limit();
        $needle = mb_strtolower($term);

        // Alle Seiten der Site in der gewünschten Sprache (Tiefensuche, Standardsprache als Gerüst)
        $root = $site->getRootPageId();
        $rootRow = $this->db->record('pages', $root, $this->tree->columns(), 'Seite');
        $defaultRows = $this->tree->subtree($root, PageTree::MAX_LEVELS, $languageId > 0 ? ApiRequest::HIDDEN_ALL : $hiddenMode, $rootRow);
        $rows = $this->tree->localize($defaultRows, $languageId, $hiddenMode);
        $pageUids = array_map([PagesHandler::class, 'defaultUid'], $rows);

        $pageHits = [];
        foreach ($rows as $row) {
            foreach (['title', 'nav_title', 'seo_title'] as $field) {
                if (isset($row[$field]) && str_contains(mb_strtolower((string)$row[$field]), $needle)) {
                    $pageHits[] = $row;
                    break;
                }
            }
            if (count($pageHits) >= $limit) {
                break;
            }
        }
        $counts = $this->tree->contentCounts(array_map([PagesHandler::class, 'defaultUid'], $pageHits), $languageId, $hiddenMode);
        $pages = [];
        foreach ($pageHits as $row) {
            $pages[] = $this->pages->format($row, $site, ($counts[PagesHandler::defaultUid($row)] ?? 0) > 0);
        }

        $content = [];
        foreach (array_chunk($pageUids, 500) as $chunk) {
            if (count($content) >= $limit) {
                break;
            }
            $queryBuilder = $this->db->query('tt_content');
            $like = $this->db->contains($queryBuilder, $term);
            $queryBuilder->select('uid', 'pid', 'colPos', 'CType', 'header', 'header_layout', 'bodytext', 'hidden', 'tstamp', 'sorting')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->like('header', $like),
                        $queryBuilder->expr()->like('bodytext', $like)
                    )
                )
                ->orderBy('pid')
                ->addOrderBy('sorting')
                ->setMaxResults($limit - count($content));
            $this->db->applyHidden($queryBuilder, $hiddenMode);
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $bodytext = (string)$row['bodytext'];
                $content[] = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)$row['pid'],
                    'colPos' => (int)$row['colPos'],
                    'CType' => (string)$row['CType'],
                    'header' => (string)$row['header'],
                    'header_layout' => (string)$row['header_layout'],
                    'bodytext_text' => HtmlSanitizer::toText($bodytext, 2000),
                    'bodytext_length' => mb_strlen($bodytext),
                    'hidden' => (bool)$row['hidden'],
                    'tstamp' => Db::iso($row['tstamp']),
                    'sorting' => (int)$row['sorting'],
                ];
            }
        }

        $news = [];
        if ($this->features->news()) {
            $queryBuilder = $this->db->query(NewsHandler::TABLE);
            $like = $this->db->contains($queryBuilder, $term);
            $queryBuilder->select('uid', 'title', 'teaser', 'datetime', 'hidden', 'path_segment', 'tstamp')
                ->from(NewsHandler::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->like('title', $like),
                        $queryBuilder->expr()->like('teaser', $like),
                        $queryBuilder->expr()->like('bodytext', $like)
                    )
                )
                ->orderBy('datetime', 'DESC')
                ->setMaxResults($limit);
            $this->db->applyHidden($queryBuilder, $hiddenMode);
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $news[] = [
                    'uid' => (int)$row['uid'],
                    'title' => (string)$row['title'],
                    'teaser' => (string)$row['teaser'],
                    'datetime' => Db::iso($row['datetime']),
                    'hidden' => (bool)$row['hidden'],
                    'slug' => (string)$row['path_segment'],
                    'tstamp' => Db::iso($row['tstamp']),
                ];
            }
        }

        return Result::ok([
            'q' => $term,
            'pages' => $pages,
            'content' => $content,
            'news' => $news,
        ]);
    }
}
