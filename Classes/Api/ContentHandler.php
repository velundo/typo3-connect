<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Database\Connection;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\ChangeSet;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Http\Validate;
use Velundo\Connect\Service\BodytextField;
use Velundo\Connect\Service\DataHandlerService;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\HtmlSanitizer;
use Velundo\Connect\Service\LogService;

/**
 * content – Inhaltselemente einer Seite lesen, ändern und anlegen.
 */
final class ContentHandler implements HandlerInterface
{
    public const WRITABLE = ['header', 'bodytext', 'hidden'];
    public const CREATABLE = ['page', 'colPos', 'CType', 'header', 'bodytext', 'hidden', 'after'];
    public const CTYPES = ['text', 'textmedia', 'header'];
    private const COLUMNS = ['uid', 'pid', 'colPos', 'CType', 'header', 'header_layout', 'bodytext', 'hidden', 'tstamp', 'sorting', 'sys_language_uid', 'image'];

    public function __construct(
        private readonly Db $db,
        private readonly BodytextField $bodytext,
        private readonly DataHandlerService $writer,
        private readonly LogService $log,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->detail((int)$request->uid)),
            'update' => $this->update($request),
            'create' => $this->create($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $page = $request->int('page', 0, 1);
        if ($page <= 0) {
            throw ApiException::validation('Parameter „page“ (uid der Seite) fehlt.', ['parameter' => 'page']);
        }
        $page = $this->defaultPageUid($page);
        $languageId = $request->languageId();
        $hiddenMode = $request->hiddenMode();
        $limit = $request->limit();
        $offset = $request->offset();

        $queryBuilder = $this->db->query('tt_content');
        $queryBuilder->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($page, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT))
            );
        $this->db->applyHidden($queryBuilder, $hiddenMode);
        $total = (int)(clone $queryBuilder)->count('uid')->executeQuery()->fetchOne();
        $rows = $queryBuilder->select(...self::COLUMNS)
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->addOrderBy('uid')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format($row);
        }
        return Result::page($items, $total, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function detail(int $uid): array
    {
        $row = $this->db->record('tt_content', $uid, self::COLUMNS, 'Inhaltselement');
        $item = $this->format($row);
        $bodytext = (string)$row['bodytext'];
        $item['bodytext'] = $bodytext;
        $item['image_count'] = (int)$row['image'];
        $item['link_count'] = preg_match_all('/<a\s[^>]*href\s*=/i', $bodytext);
        return $item;
    }

    private function update(ApiRequest $request): Result
    {
        $uid = (int)$request->uid;
        $row = $this->db->record('tt_content', $uid, self::COLUMNS, 'Inhaltselement');
        $body = $request->fields(self::WRITABLE);

        $changes = new ChangeSet();
        $changes->set('header', Validate::string($body, 'header', 255), (string)$row['header']);
        $changes->set('bodytext', $this->bodytext->fromBody($body), (string)$row['bodytext']);
        $changes->flag('hidden', Validate::bool($body, 'hidden'), (bool)$row['hidden']);
        $changes->requireAny(self::WRITABLE);
        // Zusatzrechte des Tokens: sichtbar schalten = publish; Text eines sichtbaren Elements ändern = live_edit
        $changed = array_keys($changes->data());
        if (in_array('hidden', $changed, true) && !$changes->data()['hidden']) {
            $request->require('publish', 'Ein Inhaltselement sichtbar schalten');
        }
        if (!(bool)$row['hidden'] && array_intersect($changed, ['header', 'bodytext']) !== []) {
            $request->require('live_edit', 'Ein sichtbares Inhaltselement ändern');
        }

        $this->writer->update('tt_content', $uid, $changes->data());
        $this->log->log(sprintf('Inhaltselement %d geändert (%s)', $uid, $changes->fieldList()), $request->tokenName, 'tt_content', $uid, (int)$row['pid']);

        $item = $this->detail($uid);
        $item['before'] = $changes->before();
        return Result::ok($item);
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $page = Validate::int($body, 'page', 1);
        if ($page === null) {
            throw ApiException::validation('Feld „page“ (uid der Seite) fehlt.', ['field' => 'page']);
        }
        $page = $this->defaultPageUid($page);
        $languageId = $request->languageId();
        $colPos = Validate::int($body, 'colPos', 0, 99999);
        $cType = Validate::string($body, 'CType', 50) ?? 'text';
        if (!in_array($cType, self::CTYPES, true)) {
            throw ApiException::validation('Feld „CType“ muss text, textmedia oder header sein.', ['field' => 'CType', 'allowed' => self::CTYPES]);
        }
        $header = Validate::string($body, 'header', 255) ?? '';
        $bodytext = $this->bodytext->fromBody($body) ?? '';
        $hidden = Validate::bool($body, 'hidden') ?? true;
        if (!$hidden) {
            $request->require('publish', 'Ein Inhaltselement sichtbar anlegen');
        }

        $after = Validate::int($body, 'after', 1);
        if ($after !== null) {
            // Hinter einem bestimmten Element: gleiche Seite, gleiche Sprache; colPos wird übernommen bzw. abgeglichen
            $afterRow = $this->db->record('tt_content', $after, ['uid', 'pid', 'colPos', 'sys_language_uid'], 'Inhaltselement');
            if ((int)$afterRow['pid'] !== $page) {
                throw ApiException::validation('Das Element aus „after“ liegt nicht auf der angegebenen Seite.', ['field' => 'after', 'page' => $page]);
            }
            if ((int)$afterRow['sys_language_uid'] !== $languageId) {
                throw ApiException::validation('Das Element aus „after“ gehört zu einer anderen Sprache.', ['field' => 'after', 'language' => $languageId], 'language_mismatch');
            }
            if ($colPos !== null && $colPos !== (int)$afterRow['colPos']) {
                throw ApiException::validation('Feld „colPos“ widerspricht der Spalte des Elements aus „after“.', ['field' => 'colPos', 'colPos' => $colPos, 'after_colPos' => (int)$afterRow['colPos']], 'colpos_mismatch');
            }
            $colPos = (int)$afterRow['colPos'];
        } else {
            // Ohne „after“: ans Ende der Spalte (hinter das letzte Element gleicher Seite, Spalte und Sprache)
            $colPos ??= 0;
            $after = $this->lastInColumn($page, $colPos, $languageId);
        }

        $data = [
            'pid' => $page,
            'colPos' => $colPos,
            'CType' => $cType,
            'header' => $header,
            'bodytext' => $bodytext,
            'hidden' => $hidden ? 1 : 0,
            'sys_language_uid' => $languageId,
        ];
        $uid = $this->writer->create('tt_content', $data, $after);
        $this->log->log(sprintf('Inhaltselement %d auf Seite %d angelegt (%s%s)', $uid, $page, $cType, $hidden ? ', versteckt' : ''), $request->tokenName, 'tt_content', $uid, $page);

        return Result::created(['uid' => $uid, 'before' => new \stdClass()]);
    }

    /** uid der Standardseite – auch wenn die uid einer Übersetzungszeile übergeben wurde; unbekannt → 404. */
    private function defaultPageUid(int $uid): int
    {
        $row = $this->db->record('pages', $uid, ['uid', 'sys_language_uid', 'l10n_parent'], 'Seite');
        return PagesHandler::defaultUid($row);
    }

    /** uid des letzten Elements (Sortierung) in Seite, Spalte und Sprache – auch versteckte; null wenn leer. */
    private function lastInColumn(int $page, int $colPos, int $languageId): ?int
    {
        $queryBuilder = $this->db->query('tt_content');
        $uid = $queryBuilder->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($page, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false || $uid === null ? null : (int)$uid;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $bodytext = (string)$row['bodytext'];
        return [
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
