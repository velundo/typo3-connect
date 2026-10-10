<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Service\Features;
use Velundo\Connect\Service\Settings;
use Velundo\Connect\Service\SiteResolver;

/**
 * GET info – Versionen, Sites, Sprachen, verfügbare Bereiche und Grenzen.
 */
final class InfoHandler implements HandlerInterface
{
    public const EXTENSION_VERSION = '1.0.1';

    public function __construct(
        private readonly SiteResolver $sites,
        private readonly Features $features,
        private readonly Settings $settings,
    ) {}

    public function handle(string $action, ApiRequest $request): Result
    {
        $sites = [];
        foreach ($this->sites->all() as $site) {
            $sites[] = $this->sites->describe($site);
        }
        return Result::ok([
            'typo3' => GeneralUtility::makeInstance(Typo3Version::class)->getVersion(),
            'php' => PHP_VERSION,
            'extension' => self::extensionVersion(),
            'sites' => $sites,
            'features' => $this->features->all(),
            'limits' => [
                'max_bodytext_kb' => $this->settings->maxBodytextKb(),
                'rate_limit_per_minute' => $this->settings->rateLimitPerMinute(),
            ],
        ]);
    }

    public static function extensionVersion(): string
    {
        // Im Composer-Betrieb liefert TYPO3 ggf. „dev-main“ (Path-Repository) – dann gilt die Version aus ext_emconf.php
        try {
            $version = ExtensionManagementUtility::getExtensionVersion(Settings::EXTENSION_KEY);
        } catch (\Throwable) {
            $version = '';
        }
        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1 ? $version : self::EXTENSION_VERSION;
    }
}
