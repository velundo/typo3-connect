<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Velundo\Connect\Http\ApiException;

/**
 * Ordnet Methode und Pfad (nach /_velundo/v1/) einem Bereich und einer Aktion zu.
 */
final class Router
{
    public const PREFIX = '/_velundo/';
    public const VERSION = 'v1';

    /**
     * Routentabelle: Methode, Muster (Segmente; {uid} = Zahl), Bereich, Aktion.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const ROUTES = [
        ['GET', 'info', 'info', 'get'],
        ['GET', 'system', 'system', 'get'],
        ['GET', 'pages', 'pages', 'list'],
        ['GET', 'pages/{uid}', 'pages', 'get'],
        ['PATCH', 'pages/{uid}', 'pages', 'update'],
        ['GET', 'content', 'content', 'list'],
        ['GET', 'content/{uid}', 'content', 'get'],
        ['PATCH', 'content/{uid}', 'content', 'update'],
        ['POST', 'content', 'content', 'create'],
        ['GET', 'news', 'news', 'list'],
        ['GET', 'news/{uid}', 'news', 'get'],
        ['POST', 'news', 'news', 'create'],
        ['PATCH', 'news/{uid}', 'news', 'update'],
        ['GET', 'redirects', 'redirects', 'list'],
        ['GET', 'redirects/{uid}', 'redirects', 'get'],
        ['POST', 'redirects', 'redirects', 'create'],
        ['PATCH', 'redirects/{uid}', 'redirects', 'update'],
        ['GET', 'seo/overview', 'seo', 'overview'],
        ['GET', 'search', 'search', 'get'],
        ['POST', 'cache/flush', 'cache', 'flush'],
    ];

    /**
     * Liefert den Teil des Pfads hinter /_velundo/ oder null, wenn die Anfrage nicht an die Schnittstelle geht.
     */
    public static function apiPath(string $path): ?string
    {
        // Am Anfang oder hinter der Site-Basis (z. B. /sub/_velundo/…); das Präfix beginnt selbst mit „/“
        $pos = strpos($path, self::PREFIX);
        if ($pos === false) {
            return null;
        }
        return trim(substr($path, $pos + strlen(self::PREFIX)), '/');
    }

    /**
     * @return array{area: string, action: string, uid: ?int}
     */
    public function match(string $method, string $apiPath): array
    {
        $segments = $apiPath === '' ? [] : explode('/', $apiPath);
        if (($segments[0] ?? '') !== self::VERSION) {
            throw ApiException::notFound('Unbekannte Schnittstellenversion – erwartet wird /_velundo/v1/.');
        }
        array_shift($segments);
        $method = strtoupper($method);
        $allowed = [];
        foreach (self::ROUTES as [$routeMethod, $pattern, $area, $action]) {
            $uid = self::matchPattern($pattern, $segments);
            if ($uid === false) {
                continue;
            }
            if ($routeMethod === $method) {
                return ['area' => $area, 'action' => $action, 'uid' => $uid];
            }
            $allowed[] = $routeMethod;
        }
        if ($allowed !== []) {
            throw ApiException::notFound(
                sprintf('Methode %s ist für diesen Endpunkt nicht vorgesehen.', $method),
                'method_not_allowed',
                ['allowed' => array_values(array_unique($allowed))]
            );
        }
        throw ApiException::notFound();
    }

    /**
     * @param list<string> $segments
     * @return int|null|false uid (int), null ohne uid, false bei Nichttreffer
     */
    private static function matchPattern(string $pattern, array $segments): int|null|false
    {
        $parts = explode('/', $pattern);
        if (count($parts) !== count($segments)) {
            return false;
        }
        $uid = null;
        foreach ($parts as $i => $part) {
            if ($part === '{uid}') {
                if (preg_match('/^[1-9]\d{0,9}$/', $segments[$i]) !== 1) {
                    return false;
                }
                $uid = (int)$segments[$i];
            } elseif ($part !== $segments[$i]) {
                return false;
            }
        }
        return $uid;
    }
}
