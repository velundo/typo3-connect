# Änderungen

## 1.0.1 – 2026-10-10

Befunde aus dem Review der 1.0.0.

- **Token-Widerruf konnte verloren gehen:** `authenticate()` schrieb beim Vermerken von „zuletzt verwendet“ die ganze
  Token-Liste zurück und konnte einen parallelen `revoke` oder ein neues Token überschreiben. Jetzt eigener
  Registry-Schlüssel `last_used_<id>`; die Liste schreibt nur noch die Kommandozeile. Ältere Stände werden weiter gelesen.
- **HTML-Vorreinigung löschte Fließtext** wie „online=Version“ (Muster für Ereignis-Attribute griff außerhalb von Tags).
  Attribute werden nur noch innerhalb von Tags entfernt.
- `GET content?page=` und `POST content {page}` akzeptieren die uid einer Übersetzungszeile und lösen sie auf die
  Standardseite auf; `after` wird gegen die Standard-uid geprüft. Ebenso `GET pages?parent=`; die Site ist dann die der Seite.
- Lesezugriffe mit `WorkspaceRestriction(0)`: Versionszeilen aus Workspaces erscheinen nicht mehr als eigene Datensätze.
- `POST content` ohne `after` sortiert ans Ende der Spalte (vorher oben); mit `after` wird dessen `colPos` übernommen,
  ein widersprechendes `colPos` → 422 `colpos_mismatch`, andere Sprache → 422 `language_mismatch`.
- `_cli_`: vor dem Anlegen wird mit `DeletedRestriction` geprüft, ob der Benutzer existiert – deaktiviert/abgelaufen →
  500 `cli_user_unavailable` statt zweitem Datensatz; Rückgabewert der Kern-Anlage wird ausgewertet; Initialisierung
  in Kern-Reihenfolge (`unpack_uc`, `fetchGroupData`, `backendSetUC`).
- Reihenfolge Token-Prüfung vor HTTPS-Prüfung (ohne Token immer 401, Vertrag); README-Abschnitt „Hinter einem Reverse-Proxy“.
- `target` einer Umleitung muss mit `/`, `http(s)://` oder `t3://page?uid=n` beginnen → sonst 422 `invalid_target`.
- Ratenbegrenzung: Zähler per Compare-and-Set (`KeyValueStorageInterface::compareAndSet`, `RegistryStorage` liest
  und schreibt `sys_registry` direkt); 429-Antworten werden nicht mehr in `sys_log` geschrieben.
- 422 `write_rejected`: Fehler des DataHandlers ohne rohe SQL-Texte (`{message, table, uid}`), Hinweis, dass Teile
  gespeichert sein können.
- Seitenbaum wird immer vollständig geladen, `hidden` filtert erst die Ergebniszeilen (sichtbare Unterseiten versteckter
  Seiten fehlen nicht mehr); `has_content` folgt dem `hidden`-Modus auch bei `GET pages/{uid}`.
- Kleineres: `title`/`nav_title`/`seo_title` ≤ 255 Zeichen; `datetime` nur ISO 8601; Kategorie-uids werden geprüft
  (422 `unknown_category`); `info.sites[].languages` nennt alle Sprachen (`getAllLanguages()`, wie die Prüfung von
  `?language=`); JSON mit `JSON_INVALID_UTF8_SUBSTITUTE`, Vorschautext (`bodytext_text`) auch bei ungültigem UTF-8;
  `sys_log` mit `event_pid` der Seite (`recpid` schreibt der Kern ab TYPO3 14 nicht mehr); README-Hinweis zu
  News-Adressen ohne Route-Enhancer (cHash).
- Live geprüft mit TYPO3 14.3.8 / PHP 8.5 (SQLite): Token-Widerruf unter parallelen Aufrufen, Ratenlimit, Reihenfolge
  Token → HTTPS, Einsortierung, Sanitizer, Seitenbaumfilter, Umleitungsziele, DataHandler-Fehler, deaktivierter `_cli_`.
- Doku: Vertrag ohne Backend-Modul (es gibt nur die CLI), `limits.rate_limit_per_minute`, `search.q`, `l10n_parent`
  nachgetragen; `ext_emconf.php` `state => beta`.
- Aufräumen: gemeinsamer `BodytextField`-Dienst und `ChangeSet` (Felder + `before`) statt vierfachem Aufbau in den Handlern.
- Prüfungen: Lost-Update beim Widerruf, Fließtext „online=Version“, Compare-and-Set, ISO-8601-Muster, ChangeSet,
  Umleitungsziele, DataHandler-Fehlertexte (`Tests/Unit/WriteHelpersTest.php`).

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
