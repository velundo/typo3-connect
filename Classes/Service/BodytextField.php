<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Velundo\Connect\Http\ApiException;

/**
 * Feld `bodytext` aus einem JSON-Rumpf: Typ und Größe prüfen (max_bodytext_kb), HTML bereinigen.
 * Gemeinsam für Inhaltselemente und Nachrichten.
 */
final class BodytextField
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * Bereinigtes HTML oder null, wenn das Feld fehlt.
     *
     * @param array<string, mixed> $body
     */
    public function fromBody(array $body, string $key = 'bodytext'): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw ApiException::validation(sprintf('Feld „%s“ muss eine Zeichenkette (HTML) sein.', $key), ['field' => $key], 'invalid_type');
        }
        $maxKb = $this->settings->maxBodytextKb();
        if (strlen($body[$key]) > $maxKb * 1024) {
            throw ApiException::validation(sprintf('Feld „%s“ ist größer als %d KB.', $key, $maxKb), ['field' => $key, 'max_kb' => $maxKb], 'bodytext_too_large');
        }
        return $this->sanitizer->sanitize($body[$key]);
    }
}
