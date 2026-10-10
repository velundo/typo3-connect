<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\PageTree;
use Velundo\Connect\Service\SiteResolver;

/**
 * GET seo/overview – Befunde über alle sichtbaren Standardseiten einer Site (höchstens 100 je Liste).
 * Als Titel gilt der wirksame Seitentitel (seo_title, sonst title).
 */
final class SeoHandler implements HandlerInterface
{
    private const LIST_MAX = 100;
    private const TITLE_MAX = 60;
    private const DESCRIPTION_MAX = 160;

    public function __construct(
        private readonly Db $db,
        private readonly PageTree $tree,
        private readonly SiteResolver $sites,
        private readonly Features $features,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $this->features->requireSeo();
        if ($action !== 'overview') {
            throw ApiException::notFound();
        }
        $site = $this->sites->resolve($request);
        $languageId = $this->sites->language($site, $request->languageId())->getLanguageId();
        $root = $site->getRootPageId();
        $rootRow = $this->db->record('pages', $root, $this->tree->columns(), 'Seite');
        $rows = $this->tree->subtree($root, PageTree::MAX_LEVELS, ApiRequest::HIDDEN_VISIBLE, (bool)$rootRow['hidden'] ? null : $rootRow);
        $rows = $this->tree->localize($rows, $languageId, ApiRequest::HIDDEN_VISIBLE);
        // Nur Standardseiten (doktype 1) tragen eigene Inhalte und SEO-Felder
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['doktype'] === 1));

        $missingTitle = [];
        $missingDescription = [];
        $noindex = [];
        $longTitle = [];
        $longDescription = [];
        $byTitle = [];
        foreach ($rows as $row) {
            $effectiveTitle = trim((string)($row['seo_title'] ?? '')) !== '' ? trim((string)$row['seo_title']) : trim((string)$row['title']);
            $description = trim((string)$row['description']);
            $entry = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'url' => $this->sites->pageUrl($site, $row),
            ];
            if ($effectiveTitle === '') {
                $missingTitle[] = $entry;
            } elseif (mb_strlen($effectiveTitle) > self::TITLE_MAX) {
                $longTitle[] = $entry;
            }
            if ($description === '') {
                $missingDescription[] = $entry;
            } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
                $longDescription[] = $entry;
            }
            if ((int)($row['no_index'] ?? 0) === 1) {
                $noindex[] = $entry;
            }
            if ($effectiveTitle !== '') {
                $byTitle[mb_strtolower($effectiveTitle)][] = (int)$row['uid'];
            }
        }
        $duplicates = [];
        foreach ($byTitle as $uids) {
            if (count($uids) > 1) {
                $duplicates[] = $uids;
            }
        }

        return Result::ok([
            'pages_total' => count($rows),
            'missing_title' => array_slice($missingTitle, 0, self::LIST_MAX),
            'missing_description' => array_slice($missingDescription, 0, self::LIST_MAX),
            'noindex' => array_slice($noindex, 0, self::LIST_MAX),
            'long_title' => array_slice($longTitle, 0, self::LIST_MAX),
            'long_description' => array_slice($longDescription, 0, self::LIST_MAX),
            'duplicate_titles' => array_slice($duplicates, 0, self::LIST_MAX),
        ]);
    }
}
