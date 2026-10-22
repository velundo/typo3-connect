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

### Rechte eines Tokens

Ohne Zusatzrecht liest ein Token alles und ändert nur, was nicht öffentlich ist: versteckte Seiten, Elemente und
Nachrichten, neue (versteckte) Inhalte, SEO-Felder, interne Umleitungen. Alles, was Inhalte öffentlich macht oder
öffentliche Inhalte verändert, braucht ein Zusatzrecht – es wird beim Erzeugen vergeben und steht in `velundo:token list`:

```bash
vendor/bin/typo3 velundo:token create "Agentur Test" --allow-publish --allow-live-edit --allow-external-redirect
```

| Option | Erlaubt |
|---|---|
| `--allow-publish` | Seiten, Inhaltselemente und Nachrichten sichtbar schalten (`hidden: false`), auch sichtbar anlegen |
| `--allow-live-edit` | Titel und Texte sichtbarer Seiten, Elemente und Nachrichten ändern (SEO-Felder sind immer erlaubt) |
| `--allow-external-redirect` | Umleitungen auf fremde Hosts oder von der Startseite (`/`) anlegen bzw. dorthin ändern |

Fehlt das Recht, antwortet die Schnittstelle mit 403 `scope_publish`, `scope_live_edit` bzw. `scope_external_redirect`.
Die velundo-App führt diese Änderungen ohnehin nur nach Freigabe eines Admins auf der Verwaltungsseite aus; ob die
Installation sie überhaupt zulässt, entscheidet, wer das Token erzeugt. Token aus 1.0.x haben keine Zusatzrechte.

Weitere Kommandos:

```bash
vendor/bin/typo3 velundo:token list              # Kennung, Bezeichnung, Erzeugt, Zuletzt verwendet, Status, Rechte
vendor/bin/typo3 velundo:token revoke <Kennung>  # Token widerrufen (sofort wirksam)
```

Prüfen, ob die Schnittstelle antwortet:

```bash
curl -s -H "Authorization: Bearer <token>" https://<host>/_velundo/v1/info
```

Ohne Token antwortet jeder Pfad unter `/_velundo/` mit `401` – ohne Versions- oder Systemangaben, auch über `http`
(die Token-Prüfung kommt vor der HTTPS-Prüfung; erst mit gültigem Token folgt über `http` die 403 `https_required`).
Mehr als 30 Anfragen ohne gültiges Token je Minute und Absenderadresse → `429` (Bremse gegen das Raten von Tokens).

### Einstellungen (Erweiterungskonfiguration)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `max_bodytext_kb` | 200 | Höchstgröße von `bodytext` beim Schreiben; größere Inhalte → 422 `bodytext_too_large` |
| `rate_limit_per_minute` | 120 | Anfragen je Minute und Token; darüber 429 mit `Retry-After` (Zähler per Compare-and-Set in `sys_registry`; bei sehr vielen gleichzeitigen Anfragen kann die Grenze um einzelne Anfragen weich sein, nie strenger). 429-Antworten landen nicht in `sys_log` |
| `require_https` | 1 | Anfragen über `http` → 403 `https_required` (im Kontext `Development` immer erlaubt) |
| `news_storage_pid` | 0 | Ablageordner für neue Nachrichten, wenn `POST news` kein `pid` übergibt |
| `news_detail_page` | 0 | Detailseite für Nachrichten; nur dann enthält `GET news` eine `url` |

Hinweis zu `news_detail_page`: Ohne Route-Enhancer für EXT:news in der Site-Konfiguration erzeugt der PageRouter die
Nachrichten-Adressen mit Abfrageparametern und `cHash` (`…?tx_news_pi1[news]=12&cHash=…`). Diese Adressen funktionieren,
sind aber nicht „sprechend“; mit konfiguriertem Enhancer liefert `url` die sprechende Adresse.

### Betrieb hinter CGI/FastCGI

Viele Hoster (Apache mit PHP-FPM) verwerfen den Kopf `Authorization`, solange die `.htaccess` ihn nicht weitergibt – die
Standard-`.htaccess` von TYPO3 tut das nicht. Ab 1.0.2 genügt das Token deshalb auch im Kopf `X-Velundo-Token: <token>`
(eigene Köpfe erreichen PHP immer); die velundo-App sendet beide. Daneben liest die Erweiterung die Servervariablen
`HTTP_AUTHORIZATION` und `REDIRECT_HTTP_AUTHORIZATION`. Für andere Programme, die nur `Authorization` senden, hilft in der
`.htaccess` `CGIPassAuth On` (Apache ab 2.4.13) oder:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

### Hinter einem Reverse-Proxy

Beendet ein Proxy oder Load-Balancer die TLS-Verbindung und spricht TYPO3 intern über `http` an, erkennt die
HTTPS-Prüfung die Anfrage nur als sicher, wenn TYPO3 den Proxy kennt. Dafür in `config/system/settings.php`
(TYPO3 12: `additional.php` bzw. Install-Tool → Configure):

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = '10.0.0.5';   // Adresse(n) des Proxys, kommagetrennt
$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxySSL'] = '*';         // oder die Proxy-Adressen, die TLS beenden
$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = 'first';
```

Die Erweiterung nutzt dieselbe Erkennung wie der Kern (`NormalizedParams::isHttps()`, also `X-Forwarded-Proto`
nur von bekannten Proxys). Ohne diese Einstellung antwortet die Schnittstelle hinter dem Proxy mit 403 `https_required`;
`require_https = 0` ist nur ein Notnagel für geschlossene Netze.

## Was die Erweiterung kann

Lesen (`GET`): `info`, `system`, `pages`, `pages/{uid}`, `content?page=`, `content/{uid}`, `news`, `news/{uid}`,
`redirects`, `redirects/{uid}`, `seo/overview`, `search?q=`.
Schreiben: `PATCH pages/{uid}` (title, nav_title, seo_title, description, no_index, no_follow, hidden, canonical –
`canonical` leer, http(s)-Adresse oder `t3://page?uid=n`; der Startpunkt einer Site lässt sich nicht verstecken),
`PATCH content/{uid}` (header, bodytext, hidden), `POST content` (standardmäßig versteckt; ohne `after` ans Ende der
Spalte, mit `after` hinter dieses Element in dessen Spalte), `POST/PATCH news`, `POST/PATCH redirects`
(`target` nur als Pfad, http(s)-Adresse oder `t3://page?uid=n`), `POST cache/flush`. Jede Schreibantwort enthält `before`
mit den vorherigen Werten. Sichtbar schalten, sichtbare Inhalte ändern und fremde Umleitungen brauchen das passende
Zusatzrecht des Tokens (siehe „Rechte eines Tokens“).

- Nachrichten nur mit `georgringer/news`, Umleitungen nur mit `typo3/cms-redirects`, SEO-Felder und `seo/overview`
  nur mit `typo3/cms-seo` – sonst `404 feature_unavailable`. `GET info` nennt die verfügbaren Bereiche unter `features`.
- Sprachen über `?language=<sys_language_uid>`; bei Übersetzungen ist `uid` die Übersetzungszeile, `l10n_parent`
  die Standardseite. Wo eine Seiten-uid erwartet wird (`parent`, `page`), darf auch die uid einer Übersetzungszeile
  stehen – sie wird auf die Standardseite aufgelöst. Mehrere Sites über `?site=<identifier>`; bei `parent` gilt die
  Site dieser Seite.
- `?hidden=visible|all|hidden` steuert, ob versteckte Datensätze erscheinen (Standard: nur sichtbare). Der Filter wirkt
  auf die Ergebniszeilen, nicht auf den Baum: sichtbare Unterseiten einer versteckten Seite werden gelistet.
- Gelesen wird nur der Live-Workspace (Versionszeilen aus Workspaces erscheinen nicht); geschrieben wird immer live.

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
  Referenzindex und Cache-Leerung des Kerns. Die Erweiterung legt keinen Benutzer an (seit 1.1.0 auch nicht aus einer
  Webanfrage): `_cli_` entsteht beim ersten Kommandozeilenaufruf von TYPO3, spätestens bei `velundo:token create`. Fehlt
  er oder ist er deaktiviert/abgelaufen, antwortet die Schnittstelle mit 500 `cli_user_unavailable` und einem Hinweis.
- Weist der DataHandler eine Änderung ab (422 `write_rejected`), nennt die Antwort Tabelle und uid, aber keine rohen
  Datenbank-Fehlertexte; einzelne Felder können bereits gespeichert sein.
- **Protokollierung:** Jeder Aufruf mit gültigem Token wird in `sys_log` vermerkt (Typ „Erweiterung“, Text
  `velundo: <Methode> <Pfad> → <Status> – Token „<Bezeichnung>“`), Änderungen zusätzlich mit Tabelle, uid, Seite und
  geänderten Feldnamen – keine Inhalte. Diese Einträge sind im Backend unter „Protokoll“ sichtbar und unterliegen
  der dort eingestellten Aufbewahrung. Anfragen ohne gültiges Token und 429-Antworten landen nicht in `sys_log`.
- Token-Metadaten schreibt nur die Kommandozeile (`create`, `revoke`); eine Anfrage vermerkt „zuletzt verwendet“ in
  einem eigenen Registry-Schlüssel je Token und kann einen Widerruf deshalb nicht überschreiben.
- Die Erweiterung gibt keine Pfade, Zugangsdaten oder Konfigurationswerte aus; `GET system` nennt die Versionen von
  TYPO3, PHP und den für die Schnittstelle relevanten Erweiterungen (`core`, `seo`, `redirects`, `news`, `workspaces`,
  `scheduler`, `velundo_connect`), Cache-Namen und den letzten Scheduler-Lauf – keine Inventarliste.
- Rechte: Wer ein Token hat, kann alle oben genannten Felder in allen Sites lesen und – im Rahmen seiner Zusatzrechte –
  ändern. Tokens deshalb je Agentur/Verbindung einzeln erzeugen, nur die nötigen Rechte vergeben und bei Bedarf widerrufen.

## Entwicklung

```bash
php Tests/smoke.php                  # Prüfungen der Dienstklassen ohne TYPO3 (PHP 8.2 und 8.5)
vendor/bin/phpunit Tests/Unit        # dieselben Prüfungen mit PHPUnit, falls installiert
```

Aufbau: `Classes/Middleware/ApiMiddleware.php` (HTTPS, Token, Ratenlimit, Routing, JSON), `Classes/Api/*` je Bereich,
`Classes/Service/*` (TokenService, RateLimiter, HtmlSanitizer, BodytextField, DataHandlerService, SiteResolver, PageTree,
LogService, CliUser), `Classes/Http/*` (ApiRequest, Validate, ChangeSet, Result, ApiException),
`Classes/Command/TokenCommand.php`. Änderungen stehen in `CHANGELOG.md`.

## Lizenz

GPL-2.0-or-later, siehe `LICENSE`.

## Fehler, Fragen, Sicherheit

- Fehler in der Erweiterung bitte als [Issue](https://github.com/velundo/typo3-connect/issues) melden – ohne Zugangsdaten, Tokens oder
  Inhalte von Kundenwebsites (Issues sind öffentlich).
- Fragen zur velundo-App, zu Konto oder Abo: [velundo.app/hilfe](https://velundo.app/hilfe) oder hello@velundo.app.
- Sicherheitslücken bitte vertraulich melden, siehe [SECURITY.md](SECURITY.md).
