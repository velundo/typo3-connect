<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Api\PagesHandler;
use Velundo\Connect\Api\RedirectsHandler;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Service\RateLimiter;
use Velundo\Connect\Service\Settings;
use Velundo\Connect\Service\Storage\ArrayStorage;
use Velundo\Connect\Service\TokenService;

/**
 * Zusatzrechte der Tokens (1.1.0), Fehlversuchs-Bremse vor der Token-Prüfung, Canonical-Format, fremde Umleitungsziele.
 */
final class ScopeTest extends TestCase
{
    public function testTokenScopesAreStoredNormalizedAndReturnedOnAuthenticate(): void
    {
        $storage = new ArrayStorage();
        $service = new TokenService($storage, static fn(): int => 1_800_000_000);
        $created = $service->create('Agentur Test', ['LIVE_EDIT', 'publish', 'publish']);
        self::assertSame(['publish', 'live_edit'], $created['scopes']);
        self::assertSame(['publish', 'live_edit'], $service->authenticate('Bearer ' . $created['token'])['scopes']);
        self::assertSame(['publish', 'live_edit'], $service->list()[0]['scopes']);

        $plain = $service->create('Nur Entwürfe');
        self::assertSame([], $plain['scopes']);
        self::assertSame([], $service->authenticate('Bearer ' . $plain['token'])['scopes']);
    }

    public function testUnknownScopeIsRejected(): void
    {
        $service = new TokenService(new ArrayStorage());
        $this->expectException(\InvalidArgumentException::class);
        $service->create('Agentur Test', ['delete']);
    }

    public function testLegacyTokenWithoutScopesFieldHasNoExtraRights(): void
    {
        $storage = new ArrayStorage();
        $storage->set(TokenService::STORAGE_KEY, ['abcd1234' => ['name' => 'Alt', 'hash' => TokenService::hash(str_repeat('a', 43)), 'created' => 1, 'revoked' => null]]);
        $service = new TokenService($storage);
        self::assertSame([], $service->authenticate('Bearer ' . str_repeat('a', 43))['scopes']);
        self::assertSame([], $service->list()[0]['scopes']);
    }

    public function testRequireScopeThrows403WithHint(): void
    {
        $request = new ApiRequest('PATCH', [], '{}', 7, 'Agentur Test', ['publish']);
        self::assertTrue($request->can('publish'));
        self::assertFalse($request->can('live_edit'));
        $request->require('publish', 'Sichtbar schalten'); // kein Fehler
        try {
            $request->require('live_edit', 'Den Titel einer sichtbaren Seite ändern');
            self::fail('403 erwartet');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('scope_live_edit', $e->errorCode);
            self::assertStringContainsString('--allow-live-edit', $e->getMessage());
            self::assertStringContainsString('Den Titel einer sichtbaren Seite ändern', $e->getMessage());
        }
    }

    public function testAuthFailuresAreLimitedPerRemoteAddress(): void
    {
        $now = 1_800_000_000;
        $limiter = new RateLimiter(new ArrayStorage(), new Settings([]), static function () use (&$now): int {
            return $now;
        });
        for ($i = 1; $i < RateLimiter::AUTH_FAILURES_PER_MINUTE; $i++) {
            self::assertTrue($limiter->hitAuthFailure('203.0.113.5')['allowed'], "Versuch $i");
        }
        self::assertTrue($limiter->hitAuthFailure('203.0.113.5')['allowed']);
        self::assertFalse($limiter->hitAuthFailure('203.0.113.5')['allowed']);
        self::assertTrue($limiter->hitAuthFailure('203.0.113.6')['allowed'], 'andere Adresse zählt getrennt');
        $now += 60;
        self::assertTrue($limiter->hitAuthFailure('203.0.113.5')['allowed'], 'neues Minutenfenster');
    }

    public function testCanonicalFormat(): void
    {
        self::assertTrue(PagesHandler::isValidCanonical(''));
        self::assertTrue(PagesHandler::isValidCanonical('https://www.example.de/seite/'));
        self::assertTrue(PagesHandler::isValidCanonical('t3://page?uid=12'));
        self::assertFalse(PagesHandler::isValidCanonical('/relativ'));
        self::assertFalse(PagesHandler::isValidCanonical('javascript:alert(1)'));
        self::assertFalse(PagesHandler::isValidCanonical("https://www.example.de/a b"));
        self::assertFalse(PagesHandler::isValidCanonical("https://www.example.de/\x01"));
    }

    public function testExternalRedirectTarget(): void
    {
        $hosts = ['www.example.de', 'example.de', 'shop.example.de'];
        self::assertFalse(RedirectsHandler::isExternalTarget('/neu', $hosts));
        self::assertFalse(RedirectsHandler::isExternalTarget('t3://page?uid=5', $hosts));
        self::assertFalse(RedirectsHandler::isExternalTarget('https://www.example.de/neu', $hosts));
        self::assertFalse(RedirectsHandler::isExternalTarget('https://EXAMPLE.de/neu', $hosts));
        self::assertFalse(RedirectsHandler::isExternalTarget('https://shop.example.de/', $hosts));
        self::assertTrue(RedirectsHandler::isExternalTarget('https://fremd.example.org/', $hosts));
        self::assertTrue(RedirectsHandler::isExternalTarget('https://www.example.de.evil.example/', $hosts));
        self::assertTrue(RedirectsHandler::isExternalTarget('https://www.example.de/', []), 'ohne bekannte Hosts gilt http(s) als fremd');
    }
}
