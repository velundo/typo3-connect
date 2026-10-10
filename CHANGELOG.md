# Änderungen

## 1.0.0 – 2026-10-10

Erste Fassung nach Schnittstellenvertrag v1 (`Documentation/API.md`).

- Middleware unter `/_velundo/v1/` vor der Site-Auflösung des Frontends: HTTPS-Pflicht (außer Development),
  Bearer-Token mit SHA-256-Hash in `sys_registry`, Ratenbegrenzung je Token (Standard 120/min, 429 mit `Retry-After`),
  einheitliches Fehlerformat `{"error":{"code","message","details"}}`, Kopfzeilen `X-Velundo-Api: 1` und `Cache-Control: no-store`.
- Lesen: `info`, `system`, `pages` (Baum mit `parent`, `depth` 1–5, `search`, `hidden`, `language`, `site`), `pages/{uid}`
  mit `content_summary`, `content?page=`, `content/{uid}`, `news`, `news/{uid}`, `redirects`, `redirects/{uid}`,
  `seo/overview`, `search?q=`. Die Basis darf in einem Unterverzeichnis liegen (`/<pfad>/_velundo/v1/`).
- Schreiben ausschließlich über den DataHandler als `_cli_`: `PATCH pages/{uid}`, `PATCH content/{uid}`, `POST content`,
  `POST/PATCH news`, `POST/PATCH redirects` (409 bei gleicher Quelle), `POST cache/flush`; jede Antwort mit `before`.
- `bodytext` begrenzt (`max_bodytext_kb`) und über den HTML-Sanitizer des Kerns bereinigt.
- Kommando `velundo:token create|list|revoke`.
- Protokoll jedes Aufrufs in `sys_log` (Token-Bezeichnung, Aktion, Status, bei Änderungen Tabelle/uid/Felder).
- Prüfungen: `Tests/Unit` (TokenService, RateLimiter, Router, HtmlSanitizer, Validierung), `Tests/smoke.php` ohne TYPO3.
