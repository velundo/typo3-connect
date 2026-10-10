<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Html\DefaultSanitizerBuilder;
use TYPO3\CMS\Core\Html\SanitizerBuilderFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bereinigt HTML für bodytext: erst eine eigene Grobreinigung (Skripte, Ereignis-Attribute, javascript:-Adressen),
 * dann der HTML-Sanitizer des TYPO3-Kerns mit dem Regelwerk „default“ (dasselbe, das die RTE-Speicherung nutzt).
 */
final class HtmlSanitizer
{
    private const FORBIDDEN_ELEMENTS = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'base', 'form', 'svg', 'math'];

    public function sanitize(string $html): string
    {
        $html = $this->preClean($html);
        if (isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['htmlSanitizer']['default']) && class_exists(SanitizerBuilderFactory::class)) {
            // Konfiguriertes Regelwerk „default“: build('default') liefert den Builder, dessen build() den Sanitizer
            $html = GeneralUtility::makeInstance(SanitizerBuilderFactory::class)->build('default')->build()->sanitize($html);
        } elseif (class_exists(DefaultSanitizerBuilder::class)) {
            // Ohne TYPO3-Bootstrap (Prüfstand): Standard-Regelwerk des Kerns direkt
            $html = (new DefaultSanitizerBuilder())->build()->sanitize($html);
        }
        return $html;
    }

    /**
     * Grobreinigung ohne Abhängigkeiten – auch in Prüfungen ohne TYPO3 lauffähig.
     */
    public function preClean(string $html): string
    {
        // Elemente mit Inhalt vollständig entfernen
        foreach (self::FORBIDDEN_ELEMENTS as $element) {
            $html = (string)preg_replace('#<' . $element . '\b[^>]*>.*?</' . $element . '\s*>#is', '', $html);
            $html = (string)preg_replace('#<' . $element . '\b[^>]*/?>#i', '', $html);
            $html = (string)preg_replace('#</' . $element . '\s*>#i', '', $html);
        }
        // Attribute nur innerhalb von Tags bereinigen – Fließtext wie „online=Version“ bleibt unangetastet
        return (string)preg_replace_callback('#<[a-z][^>]*>#i', static fn(array $m): string => self::cleanTag($m[0]), $html);
    }

    /** Entfernt aus einem einzelnen Start-Tag Ereignis-Attribute und Skript-Adressen. */
    private static function cleanTag(string $tag): string
    {
        // Ereignis-Attribute (onclick, onload, …) in allen Schreibweisen
        $tag = (string)preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag);
        // javascript:/vbscript:/data:text/html-Adressen in href/src/action
        return (string)preg_replace_callback(
            '#\s(href|src|action|formaction|xlink:href)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
            static function (array $m): string {
                $value = $m[3] !== '' ? $m[3] : ($m[4] !== '' ? $m[4] : ($m[5] ?? ''));
                $probe = strtolower((string)preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
                if (str_starts_with($probe, 'javascript:') || str_starts_with($probe, 'vbscript:') || str_starts_with($probe, 'data:text/html')) {
                    return '';
                }
                return $m[0];
            },
            $tag
        );
    }

    /**
     * Klartext aus HTML: Tags entfernen, Entitäten auflösen, Leerraum zusammenfassen, auf Länge kürzen.
     */
    public static function toText(string $html, int $maxLength = 2000): string
    {
        $text = (string)preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }
        return $text;
    }
}
