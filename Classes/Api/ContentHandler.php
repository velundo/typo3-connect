<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Database\Connection;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Http\Validate;
use Velundo\Connect\Service\DataHandlerService;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\HtmlSanitizer;
use Velundo\Connect\Service\LogService;
use Velundo\Connect\Service\Settings;

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
        private readonly Settings $settings,
        private readonly HtmlSanitizer $sanitizer,
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
        $this->db->record('pages', $page, ['uid'], 'Seite');
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

        $data = [];
        $before = [];
        $header = Validate::string($body, 'header', 255);
        if ($header !== null) {
            $data['header'] = $header;
            $before['header'] = (string)$row['header'];
        }
        $bodytext = $this->bodytext($body);
        if ($bodytext !== null) {
            $data['bodytext'] = $bodytext;
            $before['bodytext'] = (string)$row['bodytext'];
        }
        $hidden = Validate::bool($body, 'hidden');
        if ($hidden !== null) {
            $data['hidden'] = $hidden ? 1 : 0;
            $before['hidden'] = (bool)$row['hidden'];
        }
        if ($data === []) {
            throw ApiException::validation('Keines der Felder enthält einen Wert.', ['allowed' => self::WRITABLE], 'empty_body');
        }

        $this->writer->update('tt_content', $uid, $data);
        $this->log->log(sprintf('Inhaltselement %d geändert (%s)', $uid, implode(', ', array_keys($before))), $request->tokenName, 'tt_content', $uid, (int)$row['pid']);

        $item = $this->detail($uid);
        $item['before'] = $before;
        return Result::ok($item);
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $page = Validate::int($body, 'page', 1);
        if ($page === null) {
            throw ApiException::validation('Feld „page“ (uid der Seite) fehlt.', ['field' => 'page']);
        }
        $this->db->record('pages', $page, ['uid'], 'Seite');
        $colPos = Validate::int($body, 'colPos', 0, 99999) ?? 0;
        $cType = Validate::string($body, 'CType', 50) ?? 'text';
        if (!in_array($cType, self::CTYPES, true)) {
            throw ApiException::validation('Feld „CType“ muss text, textmedia oder header sein.', ['field' => 'CType', 'allowed' => self::CTYPES]);
        }
        $header = Validate::string($body, 'header', 255) ?? '';
        $bodytext = $this->bodytext($body) ?? '';
        $hidden = Validate::bool($body, 'hidden') ?? true;
        $after = Validate::int($body, 'after', 1);
        if ($after !== null) {
            $afterRow = $this->db->record('tt_content', $after, ['uid', 'pid'], 'Inhaltselement');
            if ((int)$afterRow['pid'] !== $page) {
                throw ApiException::validation('Das Element aus „after“ liegt nicht auf der angegebenen Seite.', ['field' => 'after', 'page' => $page]);
            }
        }

        $data = [
            'pid' => $page,
            'colPos' => $colPos,
            'CType' => $cType,
            'header' => $header,
            'bodytext' => $bodytext,
            'hidden' => $hidden ? 1 : 0,
            'sys_language_uid' => $request->languageId(),
        ];
        $uid = $this->writer->create('tt_content', $data, $after);
        $this->log->log(sprintf('Inhaltselement %d auf Seite %d angelegt (%s%s)', $uid, $page, $cType, $hidden ? ', versteckt' : ''), $request->tokenName, 'tt_content', $uid, $page);

        return Result::created(['uid' => $uid, 'before' => new \stdClass()]);
    }

    /**
     * bodytext aus dem Rumpf: Größe prüfen, HTML bereinigen.
     *
     * @param array<string, mixed> $body
     */
    private function bodytext(array $body): ?string
    {
        if (!array_key_exists('bodytext', $body) || $body['bodytext'] === null) {
            return null;
        }
        if (!is_string($body['bodytext'])) {
            throw ApiException::validation('Feld „bodytext“ muss eine Zeichenkette (HTML) sein.', ['field' => 'bodytext'], 'invalid_type');
        }
        $maxKb = $this->settings->maxBodytextKb();
        if (strlen($body['bodytext']) > $maxKb * 1024) {
            throw ApiException::validation(sprintf('Feld „bodytext“ ist größer als %d KB.', $maxKb), ['field' => 'bodytext', 'max_kb' => $maxKb], 'bodytext_too_large');
        }
        return $this->sanitizer->sanitize($body['bodytext']);
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
