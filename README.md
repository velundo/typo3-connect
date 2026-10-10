# velundo connect – TYPO3-Erweiterung `velundo_connect`

Macht eine TYPO3-Installation für die velundo-App erreichbar. Die App liest darüber Seiten, Inhaltselemente, SEO-Felder,
Nachrichten (EXT:news) und Umleitungen (EXT:redirects) und ändert sie – gezielt, nur über den DataHandler, nie löschend.
Verbindlich ist der Schnittstellenvertrag in `Documentation/API.md`.

- Composer-Paket `velundo/typo3-connect`, Extension-Key `velundo_connect`
- TYPO3 12.4 LTS, 13 LTS und 14; PHP ≥ 8.2
- Endpunktbasis: `https://<host>/_velundo/v1/` – läuft TYPO3 in einem Unterverzeichnis, entsprechend
  `https://<host>/<pfad>/_velundo/v1/` (die Middleware matcht den Präfix relativ zur Site-Basis)

## Installation

```bash
composer require velundo/typo3-connect
vendor/bin/typo3 extension:setup          # bzw. im Erweiterungsmanager aktivieren (TYPO3 ohne Composer)
vendor/bin/typo3 velundo:token create "Agentur Test"
```

Das Token wird genau einmal angezeigt. Es wird in der velundo-App als Zugangstoken der TYPO3-Verbindung eingetragen,
zusammen mit der Endpunktbasis `https://<host>/_velundo/v1/`. In TYPO3 bleibt nur der SHA-256-Hash gespeichert
(`sys_registry`, Namensraum `velundo_connect`).

Weitere Kommandos:

```bash
vendor/bin/typo3 velundo:token list              # Kennung, Bezeichnung, Erzeugt, Zuletzt verwendet, Status
vendor/bin/typo3 velundo:token revoke <Kennung>  # Token widerrufen (sofort wirksam)
```

Prüfen, ob die Schnittstelle antwortet:

```bash
curl -s -H "Authorization: Bearer <token>" https://<host>/_velundo/v1/info
```

Ohne Token antwortet jeder Pfad unter `/_velundo/` mit `401` – ohne Versions- oder Systemangaben.

### Einstellungen (Erweiterungskonfiguration)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `max_bodytext_kb` | 200 | Höchstgröße von `bodytext` beim Schreiben; größere Inhalte → 422 `bodytext_too_large` |
| `rate_limit_per_minute` | 120 | Anfragen je Minute und Token; darüber 429 mit `Retry-After` |
| `require_https` | 1 | Anfragen über `http` → 403 `https_required` (im Kontext `Development` immer erlaubt) |
| `news_storage_pid` | 0 | Ablageordner für neue Nachrichten, wenn `POST news` kein `pid` übergibt |
| `news_detail_page` | 0 | Detailseite für Nachrichten; nur dann enthält `GET news` eine `url` |

### Betrieb hinter CGI/FastCGI

Der Kopf `Authorization` muss PHP erreichen. Die Erweiterung liest zusätzlich die Servervariablen `HTTP_AUTHORIZATION`
und `REDIRECT_HTTP_AUTHORIZATION`; wenn der Kopf trotzdem fehlt, hilft in der `.htaccess`:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

## Was die Erweiterung kann

Lesen (`GET`): `info`, `system`, `pages`, `pages/{uid}`, `content?page=`, `content/{uid}`, `news`, `news/{uid}`,
`redirects`, `redirects/{uid}`, `seo/overview`, `search?q=`.
Schreiben: `PATCH pages/{uid}` (title, nav_title, seo_title, description, no_index, no_follow, hidden, canonical),
`PATCH content/{uid}` (header, bodytext, hidden), `POST content` (standardmäßig versteckt), `POST/PATCH news`,
`POST/PATCH redirects`, `POST cache/flush`. Jede Schreibantwort enthält `before` mit den vorherigen Werten.

- Nachrichten nur mit `georgringer/news`, Umleitungen nur mit `typo3/cms-redirects`, SEO-Felder und `seo/overview`
  nur mit `typo3/cms-seo` – sonst `404 feature_unavailable`. `GET info` nennt die verfügbaren Bereiche unter `features`.
- Sprachen über `?language=<sys_language_uid>`; bei Übersetzungen ist `uid` die Übersetzungszeile, `l10n_parent`
  die Standardseite. Mehrere Sites über `?site=<identifier>`.
- `?hidden=visible|all|hidden` steuert, ob versteckte Datensätze erscheinen (Standard: nur sichtbare).

## Was sie nicht kann (bewusst)

- Nichts löschen – auch nicht über Umwege; `deleted` ist in keiner Positivliste.
- Keine Dateien, keine Benutzer, keine Konfiguration, keine Workspaces (`features.workspaces` ist nur Anzeige;
  geschrieben wird immer im Live-Workspace).
- Keine anderen Felder als die des Vertrags. Unbekannte Felder → 422 `unknown_field`.
- `bodytext` wird vor dem Speichern mit dem HTML-Sanitizer des Kerns (Regelwerk `default`) bereinigt – keine
  `<script>`, keine Ereignis-Attribute, keine `javascript:`-Adressen.

## Sicherheit und Datenschutz

- Nur HTTPS (außer `TYPO3_CONTEXT=Development`). Token-Vergleich in konstanter Zeit (`hash_equals`), 401 ohne Details.
- Schreibzugriffe laufen als Backend-Benutzer `_cli_` über den DataHandler – mit Verlauf (Rückgängig im Backend),
  Referenzindex und Cache-Leerung des Kerns. Der Benutzer `_cli_` wird bei Bedarf angelegt (wie bei Scheduler-Läufen).
- **Protokollierung:** Jeder Aufruf mit gültigem Token wird in `sys_log` vermerkt (Typ „Erweiterung“, Text
  `velundo: <Methode> <Pfad> → <Status> – Token „<Bezeichnung>“`), Änderungen zusätzlich mit Tabelle, uid und
  geänderten Feldnamen – keine Inhalte. Diese Einträge sind im Backend unter „Protokoll“ sichtbar und unterliegen
  der dort eingestellten Aufbewahrung. Anfragen ohne gültiges Token landen nicht in `sys_log`.
- Die Erweiterung gibt keine Pfade, Zugangsdaten oder Konfigurationswerte aus; `GET system` nennt Versionen,
  aktive Erweiterungen, Cache-Namen und den letzten Scheduler-Lauf.
- Rechte: Wer ein Token hat, kann alle oben genannten Felder in allen Sites lesen und ändern. Tokens deshalb je
  Agentur/Verbindung einzeln erzeugen und bei Bedarf widerrufen.

## Entwicklung

```bash
php Tests/smoke.php                  # Prüfungen der Dienstklassen ohne TYPO3 (PHP 8.2 und 8.5)
vendor/bin/phpunit Tests/Unit        # dieselben Prüfungen mit PHPUnit, falls installiert
```

Aufbau: `Classes/Middleware/ApiMiddleware.php` (HTTPS, Token, Ratenlimit, Routing, JSON), `Classes/Api/*` je Bereich,
`Classes/Service/*` (TokenService, RateLimiter, HtmlSanitizer, DataHandlerService, SiteResolver, PageTree, LogService),
`Classes/Command/TokenCommand.php`. Änderungen stehen in `CHANGELOG.md`.

## Lizenz

GPL-2.0-or-later, siehe `LICENSE`.
