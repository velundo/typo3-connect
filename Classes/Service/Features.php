<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Velundo\Connect\Http\ApiException;

/**
 * Welche optionalen Bereiche diese Installation bietet (wird je Anfrage dynamisch geprüft).
 */
final class Features
{
    public function news(): bool
    {
        return ExtensionManagementUtility::isLoaded('news') && isset($GLOBALS['TCA']['tx_news_domain_model_news']);
    }

    public function redirects(): bool
    {
        return ExtensionManagementUtility::isLoaded('redirects') && isset($GLOBALS['TCA']['sys_redirect']);
    }

    public function seo(): bool
    {
        return ExtensionManagementUtility::isLoaded('seo') && isset($GLOBALS['TCA']['pages']['columns']['seo_title']);
    }

    public function workspaces(): bool
    {
        return ExtensionManagementUtility::isLoaded('workspaces');
    }

    /** @return array{news: bool, redirects: bool, seo: bool, workspaces: bool} */
    public function all(): array
    {
        return [
            'news' => $this->news(),
            'redirects' => $this->redirects(),
            'seo' => $this->seo(),
            'workspaces' => $this->workspaces(),
        ];
    }

    public function requireNews(): void
    {
        if (!$this->news()) {
            throw ApiException::featureUnavailable('news');
        }
    }

    public function requireRedirects(): void
    {
        if (!$this->redirects()) {
            throw ApiException::featureUnavailable('redirects');
        }
    }

    public function requireSeo(): void
    {
        if (!$this->seo()) {
            throw ApiException::featureUnavailable('seo');
        }
    }
}
