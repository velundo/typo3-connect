<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\ChangeSet;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Http\Validate;
use Velundo\Connect\Service\DataHandlerService;
use Velundo\Connect\Service\Db;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\LogService;

/**
 * redirects – Umleitungen von EXT:redirects lesen, anlegen und ändern. Nur wenn die Erweiterung installiert ist.
 */
final class RedirectsHandler implements HandlerInterface
{
    public const TABLE = 'sys_redirect';
    public const WRITABLE = ['target', 'target_statuscode', 'disabled'];
    public const CREATABLE = ['source_host', 'source_path', 'target', 'target_statuscode'];
    public const STATUS_CODES = [301, 302, 307, 308];
    private const COLUMNS = ['uid', 'source_host', 'source_path', 'target', 'target_statuscode', 'is_regexp', 'disabled', 'hitcount', 'lasthiton', 'createdon'];

    public function __construct(
        private readonly Db $db,
        private readonly Features $features,
        private readonly DataHandlerService $writer,
        private readonly LogService $log,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $this->features->requireRedirects();
        return match ($action) {
            'list' => $this->list($request),
            'get' => Result::ok($this->format($this->db->record(self::TABLE, (int)$request->uid, self::COLUMNS, 'Umleitung'))),
            'create' => $this->create($request),
            'update' => $this->update($request),
            default => throw ApiException::notFound(),
        };
    }

    private function list(ApiRequest $request): Result
    {
        $search = $request->string('search');
        $limit = $request->limit();
        $offset = $request->offset();

        $queryBuilder = $this->db->query(self::TABLE);
        $queryBuilder->from(self::TABLE);
        if ($search !== '') {
            $like = $this->db->contains($queryBuilder, $search);
            $queryBuilder->where($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('source_path', $like),
                $queryBuilder->expr()->like('target', $like),
                $queryBuilder->expr()->like('source_host', $like)
            ));
        }
        $total = (int)(clone $queryBuilder)->count('uid')->executeQuery()->fetchOne();
        $rows = $queryBuilder->select(...self::COLUMNS)
            ->orderBy('source_host')
            ->addOrderBy('source_path')
            ->addOrderBy('uid')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return Result::page(array_map([$this, 'format'], $rows), $total, $limit, $offset);
    }

    private function create(ApiRequest $request): Result
    {
        $body = $request->fields(self::CREATABLE);
        $sourceHost = trim(Validate::string($body, 'source_host', 255) ?? '*');
        if ($sourceHost === '') {
            $sourceHost = '*';
        }
        if ($sourceHost !== '*' && preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $sourceHost) !== 1) {
            throw ApiException::validation('Feld „source_host“ muss „*“ oder ein Hostname sein.', ['field' => 'source_host']);
        }
        $sourcePath = Validate::string($body, 'source_path', 2048);
        if ($sourcePath === null || !str_starts_with($sourcePath, '/')) {
            throw ApiException::validation('Feld „source_path“ muss mit „/“ beginnen.', ['field' => 'source_path']);
        }
        $target = trim(Validate::string($body, 'target', 2048) ?? '');
        if ($target === '') {
            throw ApiException::validation('Feld „target“ fehlt.', ['field' => 'target']);
        }
        self::assertValidTarget($target);
        $statusCode = $this->statusCode($body) ?? 301;

        $queryBuilder = $this->db->query(self::TABLE);
        $existing = $queryBuilder->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($sourceHost)),
                $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath))
            )
            ->executeQuery()
            ->fetchOne();
        if ($existing !== false) {
            throw ApiException::conflict('Für diese Quelle gibt es bereits eine Umleitung.', ['uid' => (int)$existing, 'source_host' => $sourceHost, 'source_path' => $sourcePath]);
        }

        $uid = $this->writer->create(self::TABLE, [
            'pid' => 0,
            'source_host' => $sourceHost,
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => $statusCode,
            'is_regexp' => 0,
            'disabled' => 0,
        ]);
        $this->log->log(sprintf('Umleitung %d angelegt (%s)', $uid, $statusCode), $request->tokenName, self::TABLE, $uid, 0);
        return Result::created(['uid' => $uid, 'before' => new \stdClass()]);
    }

    private function update(ApiRequest $request): Result
    {
        $uid = (int)$request->uid;
        $row = $this->db->record(self::TABLE, $uid, self::COLUMNS, 'Umleitung');
        $body = $request->fields(self::WRITABLE);

        $changes = new ChangeSet();
        $target = Validate::string($body, 'target', 2048);
        if ($target !== null) {
            $target = trim($target);
            if ($target === '') {
                throw ApiException::validation('Feld „target“ darf nicht leer sein.', ['field' => 'target']);
            }
            self::assertValidTarget($target);
        }
        $changes->set('target', $target, (string)$row['target']);
        $changes->set('target_statuscode', $this->statusCode($body), (int)$row['target_statuscode']);
        $changes->flag('disabled', Validate::bool($body, 'disabled'), (bool)$row['disabled']);
        $changes->requireAny(self::WRITABLE);

        $this->writer->update(self::TABLE, $uid, $changes->data());
        $this->log->log(sprintf('Umleitung %d geändert (%s)', $uid, $changes->fieldList()), $request->tokenName, self::TABLE, $uid, 0);

        $item = $this->format($this->db->record(self::TABLE, $uid, self::COLUMNS, 'Umleitung'));
        $item['before'] = $changes->before();
        return Result::ok($item);
    }

    /** Erlaubte Ziele: absoluter Pfad (nicht protokollrelativ „//host“), http(s)-Adresse oder Seitenverweis t3://page?uid=n. */
    public static function isValidTarget(string $target): bool
    {
        return preg_match('#^(/(?!/)|https?://|t3://page\?uid=\d+)#', $target) === 1 && preg_match('/[\s\x00-\x1f]/', $target) !== 1;
    }

    private static function assertValidTarget(string $target): void
    {
        if (!self::isValidTarget($target)) {
            throw ApiException::validation(
                'Feld „target“ muss ein Pfad (/…), eine http(s)-Adresse oder ein Seitenverweis t3://page?uid=<uid> sein.',
                ['field' => 'target'],
                'invalid_target'
            );
        }
    }

    /** @param array<string, mixed> $body */
    private function statusCode(array $body): ?int
    {
        $code = Validate::int($body, 'target_statuscode');
        if ($code !== null && !in_array($code, self::STATUS_CODES, true)) {
            throw ApiException::validation('Feld „target_statuscode“ muss 301, 302, 307 oder 308 sein.', ['field' => 'target_statuscode', 'allowed' => self::STATUS_CODES]);
        }
        return $code;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        return [
            'uid' => (int)$row['uid'],
            'source_host' => (string)$row['source_host'],
            'source_path' => (string)$row['source_path'],
            'target' => (string)$row['target'],
            'target_statuscode' => (int)$row['target_statuscode'],
            'is_regexp' => (bool)$row['is_regexp'],
            'disabled' => (bool)$row['disabled'],
            'hitcount' => (int)$row['hitcount'],
            'lasthiton' => Db::iso($row['lasthiton']),
            'createdon' => Db::iso($row['createdon']),
        ];
    }
}
