<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;

/**
 * Site und Sprache einer Anfrage sowie absolute Adressen von Seiten (SiteFinder/PageRouter).
 */
final class SiteResolver
{
    public function __construct(private readonly SiteFinder $siteFinder) {}

    /** Site aus ?site=<identifier>, sonst die erste Site der Installation. */
    public function resolve(ApiRequest $request): Site
    {
        $identifier = $request->string('site');
        if ($identifier !== '') {
            try {
                return $this->siteFinder->getSiteByIdentifier($identifier);
            } catch (SiteNotFoundException) {
                throw ApiException::notFound(sprintf('Die Site „%s“ ist nicht bekannt.', $identifier), 'site_not_found', ['site' => $identifier]);
            }
        }
        $sites = $this->all();
        if ($sites === []) {
            throw ApiException::notFound('In dieser Installation ist keine Site konfiguriert.', 'site_not_found');
        }
        return reset($sites);
    }

    /** @return list<Site> */
    public function all(): array
    {
        $sites = $this->siteFinder->getAllSites();
        ksort($sites);
        return array_values($sites);
    }

    /** Sprache der Site aus ?language=<id>; unbekannte Sprache → 422. */
    public function language(Site $site, int $languageId): SiteLanguage
    {
        foreach ($site->getAllLanguages() as $language) {
            if ($language->getLanguageId() === $languageId) {
                return $language;
            }
        }
        throw ApiException::validation(sprintf('Die Sprache %d gibt es in der Site „%s“ nicht.', $languageId, $site->getIdentifier()), ['language' => $languageId], 'language_unknown');
    }

    /** Site, zu der eine Seite gehört; ohne Zuordnung die übergebene Rückfallsite. */
    public function siteForPage(int $pageUid, ?Site $fallback = null): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return $fallback;
        }
    }

    /**
     * Absolute Adresse einer Seite. Erwartet eine Zeile aus `pages` (uid, slug, sys_language_uid, l10n_parent).
     * Bei Übersetzungen zeigt die Adresse auf die Standardseite in der jeweiligen Sprache.
     *
     * @param array<string, mixed> $row
     */
    public function pageUrl(?Site $site, array $row): ?string
    {
        if ($site === null) {
            return null;
        }
        $languageId = (int)($row['sys_language_uid'] ?? 0);
        $defaultUid = $languageId > 0 ? (int)($row['l10n_parent'] ?? 0) : (int)$row['uid'];
        $slug = (string)($row['slug'] ?? '');
        try {
            $language = $site->getLanguageById($languageId);
        } catch (\Throwable) {
            return null;
        }
        try {
            $route = [
                'uid' => $defaultUid,
                'pid' => (int)($row['pid'] ?? 0),
                'slug' => $slug,
                'sys_language_uid' => $languageId,
                'l10n_parent' => $languageId > 0 ? $defaultUid : 0,
                'doktype' => (int)($row['doktype'] ?? 1),
            ];
            if ($languageId > 0) {
                $route['_LOCALIZED_UID'] = (int)$row['uid'];
                $route['_PAGES_OVERLAY_UID'] = (int)$row['uid'];
            }
            return (string)$site->getRouter()->generateUri($route, ['_language' => $language]);
        } catch (\Throwable) {
            // Rückfall: Sprachbasis plus Slug (entspricht dem Ergebnis des PageRouters ohne Erweiterungen)
            return rtrim((string)$language->getBase(), '/') . '/' . ltrim($slug, '/');
        }
    }

    /**
     * Absolute Adresse einer beliebigen Seite der Site mit Abfrageparametern (z. B. Nachrichten-Detail).
     *
     * @param array<string, mixed> $parameters
     */
    public function pageUrlWithParameters(Site $site, int $pageUid, int $languageId, array $parameters): ?string
    {
        try {
            $parameters['_language'] = $site->getLanguageById($languageId);
            return (string)$site->getRouter()->generateUri($pageUid, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Beschreibung einer Site für den Endpunkt info.
     *
     * @return array{identifier: string, base: string, languages: list<array{id: int, title: string, locale: string}>}
     */
    public function describe(Site $site): array
    {
        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $languages[] = [
                'id' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'locale' => self::localeName($language),
            ];
        }
        return [
            'identifier' => $site->getIdentifier(),
            'base' => (string)$site->getBase(),
            'languages' => $languages,
        ];
    }

    /** Locale als „de_DE“ (TYPO3 ≥ 12.3 liefert ein Locale-Objekt, ältere Stände eine Zeichenkette). */
    public static function localeName(SiteLanguage $language): string
    {
        $locale = $language->getLocale();
        if (is_object($locale) && method_exists($locale, 'getLanguageCode')) {
            $name = (string)$locale->getLanguageCode();
            $country = method_exists($locale, 'getCountryCode') ? (string)$locale->getCountryCode() : '';
            return $country !== '' ? $name . '_' . $country : $name;
        }
        $name = (string)$locale;
        return (string)preg_replace('/[.@].*$/', '', $name);
    }
}
