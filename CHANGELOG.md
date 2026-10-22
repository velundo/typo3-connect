# Änderungen

## 1.1.0 – 2026-10-22

Befunde aus dem Code-Review vom 21.10.2026: Die Admin-Pflicht für Veröffentlichen, Live-Änderungen und fremde
Umleitungen galt bisher nur in der velundo-App – mit jedem Token war serverseitig alles möglich.

- **Zusatzrechte je Token:** Ein Token ändert ohne Zusatzrecht nur unveröffentlichte Inhalte und Metadaten.
  `velundo:token create "…" --allow-publish` erlaubt das Sichtbarschalten (Seiten, Inhaltselemente, Nachrichten –
  auch das sichtbare Anlegen), `--allow-live-edit` das Ändern von Titeln und Texten sichtbarer Datensätze
  (SEO-Felder bleiben frei), `--allow-external-redirect` Umleitungen auf fremde Hosts oder von der Startseite (`/`).
  Fehlt das Recht: 403 `scope_publish`, `scope_live_edit` bzw. `scope_external_redirect` mit Hinweis auf das Kommando.
  `velundo:token list` zeigt die Rechte. Bestehende Token aus 1.0.x haben keine Zusatzrechte – für Veröffentlichungen
  ein neues Token mit den gewünschten Rechten erzeugen.
- **Startpunkt bleibt sichtbar:** `hidden: true` auf dem Startpunkt einer Site (`is_siteroot`) → 422 `site_root`.
- **`canonical` geprüft:** leer, http(s)-Adresse oder `t3://page?uid=n` – sonst 422 `invalid_canonical`.
- **Kein Backend-Benutzer aus einer Webanfrage:** Fehlt `_cli_`, antwortet die Schnittstelle mit 500
  `cli_user_unavailable` und einem Hinweis; TYPO3 legt den Benutzer beim ersten Kommandozeilenaufruf an
  (spätestens bei `velundo:token create`).
- **`GET system` knapper:** Versionen nur noch von `core`, `seo`, `redirects`, `news`, `workspaces`, `scheduler` und
  `velundo_connect` – keine Inventarliste aller Erweiterungen.
- **Bremse vor der Token-Prüfung:** Mehr als 30 Anfragen ohne gültiges Token je Minute und Absenderadresse → 429.

## 1.0.3 – 2026-10-11

Befunde aus dem Livetest mit georgringer/news 14.1.

- **Ablageordner früh erkennbar:** `GET info` nennt unter `news` den Standardordner für neue Nachrichten (`storage_pid`)
  und die Detailseite (`detail_page`), jeweils 0 = nicht gesetzt. Die App lehnt eine neue Nachricht ohne `pid` damit schon
  beim Vorschlag ab, statt erst beim Ausführen.
- **Ordner je Nachricht:** Listen- und Detaileinträge von `GET news` enthalten `pid` (Ablageordner) – so findet die App
  den Ordner vorhandener Nachrichten.
- **Versionsangabe:** `GET info` meldete bei Installation aus einem Path-Repository („dev-main“) noch 1.0.1. Eine Prüfung
  hält die Konstante jetzt an `ext_emconf.php` gebunden.

## 1.0.2 – 2026-10-11

Befund aus dem Livetest auf einem Hoster mit Apache und PHP-FPM.

- **Token kam nicht an:** Die Standard-`.htaccess` von TYPO3 gibt den Kopf `Authorization` nicht an PHP weiter; jeder
  Aufruf endete mit 401. Das Token gilt jetzt ersatzweise im Kopf `X-Velundo-Token`, den PHP immer erhält. Die velundo-App
  sendet beide Köpfe; `.htaccess`-Anpassungen sind nicht mehr nötig.

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
