<?php

declare(strict_types=1);

namespace Velundo\Connect\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use Velundo\Connect\Api\CacheHandler;
use Velundo\Connect\Api\ContentHandler;
use Velundo\Connect\Api\HandlerInterface;
use Velundo\Connect\Api\InfoHandler;
use Velundo\Connect\Api\NewsHandler;
use Velundo\Connect\Api\PagesHandler;
use Velundo\Connect\Api\RedirectsHandler;
use Velundo\Connect\Api\SearchHandler;
use Velundo\Connect\Api\SeoHandler;
use Velundo\Connect\Api\SystemHandler;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Service\LogService;
use Velundo\Connect\Service\RateLimiter;
use Velundo\Connect\Service\Router;
use Velundo\Connect\Service\Settings;
use Velundo\Connect\Service\TokenService;

/**
 * Einstieg der Schnittstelle unter /_velundo/v1/: HTTPS-Pflicht, Token, Ratenbegrenzung, Routing, JSON-Antworten.
 * Alle anderen Anfragen werden unverändert an das Frontend weitergereicht.
 */
final class ApiMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    // JSON_INVALID_UTF8_SUBSTITUTE: ungültige Bytes aus der Datenbank brechen die Antwort nicht, sondern werden ersetzt
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string, HandlerInterface> */
    private readonly array $handlers;

    public function __construct(
        private readonly Router $router,
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
        private readonly LogService $log,
        InfoHandler $info,
        SystemHandler $system,
        PagesHandler $pages,
        ContentHandler $content,
        NewsHandler $news,
        RedirectsHandler $redirects,
        SeoHandler $seo,
        SearchHandler $search,
        CacheHandler $cache,
    ) {
        $this->handlers = [
            'info' => $info,
            'system' => $system,
            'pages' => $pages,
            'content' => $content,
            'news' => $news,
            'redirects' => $redirects,
            'seo' => $seo,
            'search' => $search,
            'cache' => $cache,
        ];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiPath = Router::apiPath($request->getUri()->getPath());
        if ($apiPath === null) {
            return $handler->handle($request);
        }

        $tokenName = null;
        $status = 500;
        try {
            $result = $this->dispatch($request, $apiPath, $tokenName);
            $status = $result->status;
            return $this->json($result->data, $result->status, $result->headers);
        } catch (ApiException $e) {
            $status = $e->status;
            $headers = [];
            if ($e->status === 401) {
                $headers['WWW-Authenticate'] = 'Bearer realm="velundo"';
            }
            if ($e->status === 429) {
                $headers['Retry-After'] = (string)(int)($e->details['retry_after'] ?? 60);
            }
            return $this->json($e->toArray(), $e->status, $headers);
        } catch (\Throwable $e) {
            $this->logger?->error('velundo: ' . get_class($e) . ': ' . $e->getMessage(), ['exception' => $e]);
            $details = Environment::getContext()->isDevelopment()
                ? ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()]
                : [];
            return $this->json((new ApiException(500, 'internal_error', 'Interner Fehler in der Schnittstelle.', $details))->toArray(), 500);
        } finally {
            // Jeder Aufruf mit gültigem Token landet in sys_log (Aktion und Ergebnis, keine Inhalte) –
            // außer 429, sonst würde ein Ansturm das Protokoll fluten.
            if ($tokenName !== null && $status !== 429) {
                $this->log->log(sprintf('%s %s → %d', strtoupper($request->getMethod()), $apiPath, $status), $tokenName);
            }
        }
    }

    private function dispatch(ServerRequestInterface $request, string $apiPath, ?string &$tokenName): Result
    {
        // Erst das Token: ohne gültiges Token antwortet die Schnittstelle immer 401 (Vertrag), auch über http.
        $token = $this->tokens->authenticate($this->authorizationHeader($request));
        if ($token === null) {
            throw ApiException::unauthorized();
        }
        $tokenName = $token['name'];

        if ($this->settings->requireHttps() && !Environment::getContext()->isDevelopment() && !$this->isHttps($request)) {
            throw ApiException::forbidden('https_required', 'Die Schnittstelle ist nur über HTTPS erreichbar.');
        }

        $rate = $this->rateLimiter->hit($token['id']);
        if (!$rate['allowed']) {
            throw ApiException::rateLimited($rate['retry_after']);
        }

        $route = $this->router->match($request->getMethod(), $apiPath);
        $apiRequest = new ApiRequest(
            strtoupper($request->getMethod()),
            $request->getQueryParams(),
            (string)$request->getBody(),
            $route['uid'],
            $token['name']
        );
        return $this->handlers[$route['area']]->handle($route['action'], $apiRequest);
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        $params = $request->getAttribute('normalizedParams');
        if ($params instanceof NormalizedParams) {
            return $params->isHttps();
        }
        return $request->getUri()->getScheme() === 'https';
    }

    /** Authorization-Header – bei CGI/FastCGI-Betrieb auch aus den Servervariablen. */
    private function authorizationHeader(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header !== '') {
            return $header;
        }
        $server = $request->getServerParams();
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
                return $server[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status, array $headers = []): ResponseInterface
    {
        $response = (new JsonResponse($data, $status, [], self::JSON_FLAGS))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Velundo-Api', '1')
            ->withHeader('Cache-Control', 'no-store');
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
