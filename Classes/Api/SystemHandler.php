<?php

declare(strict_types=1);

namespace Velundo\Connect\Api;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Result;
use Velundo\Connect\Service\Db;

/**
 * GET system – Versionen, aktive Erweiterungen, Cache-Namen, letzter Lauf des Schedulers (keine Pfade, keine Geheimnisse).
 */
final class SystemHandler implements HandlerInterface
{
    /** Nur Erweiterungen, deren Version für die velundo-App zählt (Funktionsumfang, Fehlersuche) – keine Inventarliste der Installation. */
    public const RELEVANT_EXTENSIONS = ['core', 'seo', 'redirects', 'news', 'velundo_connect', 'workspaces', 'scheduler'];

    public function handle(string $action, ApiRequest $request): Result
    {
        $extensions = [];
        foreach (GeneralUtility::makeInstance(PackageManager::class)->getActivePackages() as $package) {
            if (!in_array($package->getPackageKey(), self::RELEVANT_EXTENSIONS, true)) {
                continue;
            }
            $composerName = $package->getValueFromComposerManifest('name');
            $extensions[] = [
                'key' => $package->getPackageKey(),
                'version' => (string)$package->getPackageMetaData()->getVersion(),
                'composer' => is_string($composerName) ? $composerName : null,
            ];
        }
        usort($extensions, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        $caches = array_keys($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? []);
        sort($caches);

        $lastRun = null;
        try {
            $run = GeneralUtility::makeInstance(Registry::class)->get('tx_scheduler', 'lastRun');
            if (is_array($run)) {
                $lastRun = Db::iso($run['end'] ?? $run['start'] ?? 0);
            }
        } catch (\Throwable) {
            $lastRun = null;
        }

        return Result::ok([
            'typo3' => GeneralUtility::makeInstance(Typo3Version::class)->getVersion(),
            'php' => PHP_VERSION,
            'extensions' => $extensions,
            'caches' => array_values(array_map('strval', $caches)),
            'scheduler_last_run' => $lastRun,
        ]);
    }
}
