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
use Velundo\Connect\Service\BodytextField;
use Velundo\Connect\Service\DataHandlerService;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\HtmlSanitizer;
use Velundo\Connect\Service\LogService;
use Velundo\Connect\Service\Settings;
use Velundo\Connect\Service\SiteResolver;

/**
 * news – Nachrichten von EXT:news (georgringer/news) lesen, anlegen und ändern. Nur wenn die Erweiterung installiert ist.
 */
final class NewsHandler implements HandlerInterface
{
    public const TABLE = 'tx_news_domain_model_news';
    public const WRITABLE = ['title', 'teaser', 'bodytext', 'datetime', 'hidden', 'categories'];
    public const CREATABLE = ['title', 'teaser', 'bodytext', 'datetime', 'categories', 'hidden', 'pid'];
    private const COLUMNS = ['uid', 'pid', 'title', 'teaser', 'bodytext', 'datetime', 'hidden', 'path_segment', 'tstamp', 'sys_language_uid'];

    public function __construct(
        private readonly Db $db,
        private readonly Features $features,
        private readonly Settings $settings,
        private readonly SiteResolver $sites,
        private readonly BodytextField $bodytext,
        private readonly DataHandlerService $writer,
        private readonly LogService $log,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $this->features->requireNews();
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->detail((int)$request->uid, $this->sites->resolve($request))),
            'create' => $this->create($request),
            'update' => $this->update($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $site = $this->sites->resolve($request);
        $languageId = $this->sites->language($site, $request->languageId())->getLanguageId();
        $hiddenMode = $request->hiddenMode();
        $search = $request->string('search');
        $limit = $request->limit();
        $offset = $request->offset();

        $queryBuilder = $this->db->query(self::TABLE);
        $queryBuilder->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)));
        $this->db->applyHidden($queryBuilder, $hiddenMode);
        if ($search !== '') {
            $like = $this->db->contains($queryBuilder, $search);
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('title', $like),
                $queryBuilder->expr()->like('teaser', $like),
                $queryBuilder->expr()->like('bodytext', $like)
            ));
        }
        $total = (int)(clone $queryBuilder)->count('uid')->executeQuery()->fetchOne();
        $rows = $queryBuilder->select(...self::COLUMNS)
            ->orderBy('datetime', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format($row, $site);
        }
        return Result::page($items, $total, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function detail(int $uid, ?Site $site): array
    {
        $row = $this->db->record(self::TABLE, $uid, self::COLUMNS, 'Nachricht');
        $item = $this->format($row, $site);
        $item['bodytext'] = (string)$row['bodytext'];
        $item['bodytext_text'] = HtmlSanitizer::toText((string)$row['bodytext'], 2000);
        return $item;
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $title = Validate::string($body, 'title', 255);
        if ($title === null || trim($title) === '') {
            throw ApiException::validation('Feld „title“ fehlt.', ['field' => 'title']);
        }
        $pid = Validate::int($body, 'pid', 1) ?? $this->settings->newsStoragePid();
        if ($pid <= 0) {
            throw ApiException::validation('Kein Ablageordner: „pid“ übergeben oder in den Erweiterungseinstellungen (news_storage_pid) hinterlegen.', ['field' => 'pid']);
        }
        $this->db->record('pages', $pid, ['uid'], 'Ablageordner');
        $categories = Validate::intList($body, 'categories') ?? [];
        $this->assertCategoriesExist($categories);
        if (!(Validate::bool($body, 'hidden') ?? true)) {
            $request->require('publish', 'Eine Nachricht sichtbar anlegen');
        }
        $data = [
            'pid' => $pid,
            'title' => $title,
            'teaser' => strip_tags(Validate::string($body, 'teaser', 5000) ?? ''),
            'bodytext' => $this->bodytext->fromBody($body) ?? '',
            'datetime' => Validate::datetime($body, 'datetime') ?? time(),
            'hidden' => (Validate::bool($body, 'hidden') ?? true) ? 1 : 0,
            'categories' => implode(',', $categories),
            'sys_language_uid' => $request->languageId(),
        ];
        $uid = $this->writer->create(self::TABLE, $data);
        $this->log->log(sprintf('Nachricht %d angelegt%s', $uid, $data['hidden'] ? ' (versteckt)' : ''), $request->tokenName, self::TABLE, $uid, $pid);
        return Result::created(['uid' => $uid, 'before' => new \stdClass()]);
    }

    private function update(ApiRequest $request): Result
    {
        $uid = (int)$request->uid;
        $row = $this->db->record(self::TABLE, $uid, self::COLUMNS, 'Nachricht');
        $body = $request->fields(self::WRITABLE);

        $changes = new ChangeSet();
        $changes->set('title', Validate::string($body, 'title', 255), (string)$row['title']);
        $teaser = Validate::string($body, 'teaser', 5000);
        $changes->set('teaser', $teaser === null ? null : strip_tags($teaser), (string)$row['teaser']);
        $changes->set('bodytext', $this->bodytext->fromBody($body), (string)$row['bodytext']);
        $changes->set('datetime', Validate::datetime($body, 'datetime'), Db::iso($row['datetime']));
        $changes->flag('hidden', Validate::bool($body, 'hidden'), (bool)$row['hidden']);
        $categories = Validate::intList($body, 'categories');
        if ($categories !== null) {
            $this->assertCategoriesExist($categories);
            $changes->set('categories', implode(',', $categories), array_map(static fn(array $c): int => $c['uid'], $this->categories($uid)));
        }
        $changes->requireAny(self::WRITABLE);
        // Zusatzrechte des Tokens: sichtbar schalten = publish; Titel/Text einer sichtbaren Nachricht ändern = live_edit
        $changed = array_keys($changes->data());
        if (in_array('hidden', $changed, true) && !$changes->data()['hidden']) {
            $request->require('publish', 'Eine Nachricht sichtbar schalten');
        }
        if (!(bool)$row['hidden'] && array_intersect($changed, ['title', 'teaser', 'bodytext']) !== []) {
            $request->require('live_edit', 'Eine sichtbare Nachricht ändern');
        }

        $this->writer->update(self::TABLE, $uid, $changes->data());
        $this->log->log(sprintf('Nachricht %d geändert (%s)', $uid, $changes->fieldList()), $request->tokenName, self::TABLE, $uid, (int)$row['pid']);

        $item = $this->detail($uid, $this->sites->resolve($request));
        $item['before'] = $changes->before();
        return Result::ok($item);
    }

    /**
     * 422 `unknown_category`, wenn eine der Kategorien nicht existiert (gelöschte gelten als unbekannt).
     *
     * @param list<int> $uids
     */
    private function assertCategoriesExist(array $uids): void
    {
        if ($uids === []) {
            return;
        }
        $queryBuilder = $this->db->query('sys_category');
        $found = $queryBuilder->select('uid')
            ->from('sys_category')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchFirstColumn();
        $missing = array_values(array_diff($uids, array_map('intval', $found)));
        if ($missing !== []) {
            throw ApiException::validation(
                sprintf('Unbekannte Kategorie(n): %s.', implode(', ', $missing)),
                ['field' => 'categories', 'missing' => $missing],
                'unknown_category'
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row, ?Site $site): array
    {
        $uid = (int)$row['uid'];
        $url = null;
        $detailPage = $this->settings->newsDetailPage();
        if ($site !== null && $detailPage > 0) {
            $url = $this->sites->pageUrlWithParameters($site, $detailPage, (int)$row['sys_language_uid'], [
                'tx_news_pi1' => ['news' => $uid, 'controller' => 'News', 'action' => 'detail'],
            ]);
        }
        return [
            'uid' => $uid,
            'pid' => (int)$row['pid'],
            'title' => (string)$row['title'],
            'teaser' => (string)$row['teaser'],
            'datetime' => Db::iso($row['datetime']),
            'hidden' => (bool)$row['hidden'],
            'categories' => $this->categories($uid),
            'tags' => $this->tags($uid),
            'slug' => (string)$row['path_segment'],
            'url' => $url,
            'tstamp' => Db::iso($row['tstamp']),
        ];
    }

    /** @return list<array{uid: int, title: string}> */
    private function categories(int $newsUid): array
    {
        try {
            $queryBuilder = $this->db->query('sys_category');
            $rows = $queryBuilder->select('sys_category.uid', 'sys_category.title')
                ->from('sys_category')
                ->join('sys_category', 'sys_category_record_mm', 'mm', 'mm.uid_local = sys_category.uid')
                ->where(
                    $queryBuilder->expr()->eq('mm.uid_foreign', $queryBuilder->createNamedParameter($newsUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('mm.tablenames', $queryBuilder->createNamedParameter(self::TABLE)),
                    $queryBuilder->expr()->eq('mm.fieldname', $queryBuilder->createNamedParameter('categories'))
                )
                ->orderBy('mm.sorting_foreign')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn(array $r): array => ['uid' => (int)$r['uid'], 'title' => (string)$r['title']], $rows);
    }

    /** @return list<array{uid: int, title: string}> */
    private function tags(int $newsUid): array
    {
        try {
            $queryBuilder = $this->db->query('tx_news_domain_model_tag');
            $rows = $queryBuilder->select('tag.uid', 'tag.title')
                ->from('tx_news_domain_model_tag', 'tag')
                ->join('tag', 'tx_news_domain_model_news_tag_mm', 'mm', 'mm.uid_foreign = tag.uid')
                ->where($queryBuilder->expr()->eq('mm.uid_local', $queryBuilder->createNamedParameter($newsUid, Connection::PARAM_INT)))
                ->orderBy('mm.sorting')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn(array $r): array => ['uid' => (int)$r['uid'], 'title' => (string)$r['title']], $rows);
    }
}
