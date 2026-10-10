<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Erweiterungseinstellungen (ext_conf_template.txt) mit Standardwerten.
 */
class Settings
{
    public const EXTENSION_KEY = 'velundo_connect';

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @param array<string, mixed>|null $values Feste Werte (nur für Prüfungen); null = aus der Erweiterungskonfiguration
     */
    public function __construct(?array $values = null)
    {
        $this->values = $values;
    }

    public function maxBodytextKb(): int
    {
        return max(1, (int)$this->get('max_bodytext_kb', 200));
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, (int)$this->get('rate_limit_per_minute', 120));
    }

    public function requireHttps(): bool
    {
        return (bool)$this->get('require_https', 1);
    }

    public function newsStoragePid(): int
    {
        return max(0, (int)$this->get('news_storage_pid', 0));
    }

    public function newsDetailPage(): int
    {
        return max(0, (int)$this->get('news_detail_page', 0));
    }

    private function get(string $key, mixed $default): mixed
    {
        if ($this->values === null) {
            $this->values = [];
            try {
                $config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get(self::EXTENSION_KEY);
                if (is_array($config)) {
                    $this->values = $config;
                }
            } catch (\Throwable) {
                // Keine Konfiguration gespeichert – Standardwerte gelten.
            }
        }
        $value = $this->values[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }
}
