<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Cache\CacheManager;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Http\Validate;
use Velundo\Connect\Service\LogService;

/**
 * POST cache/flush – Seiten-Caches einzelner Seiten ({"pages":[uid,…]}) oder aller Seiten ({}) leeren.
 */
final class CacheHandler implements HandlerInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly LogService $log,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        if ($action !== 'flush') {
            throw ApiException::notFound();
        }
        $body = $request->fields(['pages'], false);
        $pages = Validate::intList($body, 'pages') ?? [];
        if ($pages !== []) {
            $tags = array_map(static fn(int $uid): string => 'pageId_' . $uid, $pages);
            $this->cacheManager->flushCachesInGroupByTags('pages', $tags);
            $this->log->log(sprintf('Seiten-Cache geleert für %s', implode(', ', $pages)), $request->tokenName);
            return Result::ok(['flushed' => 'pages', 'pages' => $pages, 'before' => new \stdClass()]);
        }
        $this->cacheManager->flushCachesInGroup('pages');
        $this->log->log('Seiten-Cache vollständig geleert', $request->tokenName);
        return Result::ok(['flushed' => 'all', 'before' => new \stdClass()]);
    }
}
