# velundo ↔ TYPO3 – Schnittstellenvertrag v1

Zwei Seiten: (1) TYPO3-Erweiterung `velundo_connect` (Composer `velundo/typo3-connect`), die der Kunde bzw. die Agentur in
der TYPO3-Installation (v12.4 LTS, v13 LTS, v14) installiert; (2) Modul `typo3` in der velundo-App, das diese API nutzt.
Beide Seiten halten sich wörtlich an diesen Vertrag. Marke klein „velundo“. Keine Hinweise auf Betreiber/Firma.

## Transport
- Basis: `https://<site-host>/_velundo/v1/` (PSR-15-Middleware der Erweiterung, Präfix fest). Nur HTTPS.
  Läuft TYPO3 in einem Unterverzeichnis, liegt die Basis entsprechend unter `https://<site-host>/<pfad>/_velundo/v1/` –
  die Middleware matcht den Präfix `/_velundo/v1/` relativ zur Site-Basis.
- Auth: `Authorization: Bearer <token>`, ersatzweise `X-Velundo-Token: <token>` (ab 1.0.2 – für Hoster, die `Authorization`
  nicht an PHP weitergeben; die App sendet beide). Token wird in TYPO3 per CLI `vendor/bin/typo3 velundo:token create "<Bezeichnung>"`
  erzeugt, nur als Hash (SHA-256) in `sys_registry` gespeichert, einmal angezeigt; mehrere Tokens mit Bezeichnung möglich,
  widerrufbar (`velundo:token revoke <Kennung>`). Fehlendes/falsches Token → 401 `{"error":{"code":"unauthorized","message":"…"}}`.
- Reihenfolge der Prüfungen: Token → HTTPS → Ratenbegrenzung → Route. Ohne gültiges Token antwortet jeder Pfad unter
  `/_velundo/` mit 401 – auch über `http`. Mit gültigem Token über `http` (und `require_https` aktiv, Kontext nicht
  `Development`) → 403 `https_required`.
- Header der Antwort: `X-Velundo-Api: 1`, `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`.
- Fehler: HTTP 400/401/403/404/409/422/429/500 mit `{"error":{"code":"<snake_case>","message":"<deutsch>","details":{}}}`.
- Ratenbegrenzung in der Erweiterung: 120 Anfragen je Minute je Token (Einstellung `rate_limit_per_minute`) → 429 mit
  `Retry-After`. Der Zähler wird per Compare-and-Set in `sys_registry` geführt; bei sehr vielen gleichzeitigen Anfragen
  kann die Grenze in seltenen Fällen um einzelne Anfragen weich sein, nie strenger. 429-Antworten werden nicht in `sys_log`
  protokolliert.
- Paginierung: `?limit=` (≤ 200, Standard 50) und `?offset=`; Antwort `{"items":[…],"total":n,"limit":l,"offset":o}`.
- Zeiten als ISO 8601 mit Versatz; Kennungen sind TYPO3-`uid` (int). Sprachen über `?language=<sys_language_uid>` (Standard 0).
  Eingaben für Zeitpunkte (`datetime`) nur nach ISO 8601 (`2026-10-10`, `2026-10-10T12:00:00+02:00`, `…Z`); andere
  Schreibweisen → 422 `invalid_type`.
- Mehrere Sites je Installation: `?site=<identifier>` (Standard: erste Site). Jede Antwort mit Seiten nennt `url` absolut.
- Übersetzungen: Wo eine Seiten-uid erwartet wird (`parent`, `page`), darf auch die uid einer Übersetzungszeile stehen;
  die Erweiterung löst sie auf die Standardseite (`l10n_parent`) auf. Bei `parent` gilt die Site dieser Seite, nicht `?site=`.

## Lesen
- `GET info` → `{"typo3":"14.3.7","php":"8.5.x","extension":"1.0.3","sites":[{"identifier":"main","base":"https://…","languages":[{"id":0,"title":"Deutsch","locale":"de_DE"}]}],"features":{"news":bool,"redirects":bool,"seo":bool,"workspaces":bool},"limits":{"max_bodytext_kb":200,"rate_limit_per_minute":120},"news":{"storage_pid":0,"detail_page":0}}`
  (`languages` nennt alle konfigurierten Sprachen der Site, auch deaktivierte – dieselbe Menge, die `?language=` zulässt;
  `news` ab 1.0.3: Standardordner neuer Nachrichten und Detailseite aus den Erweiterungseinstellungen, 0 = nicht gesetzt)
- `GET system` → `{"typo3":…,"php":…,"extensions":[{"key":"news","version":"12.x","composer":"georgringer/news"}],"caches":["pages",…],"scheduler_last_run":"…"}` (keine Pfade, keine Geheimnisse)
- `GET pages?site&parent=<uid>&depth=1..5&search=&hidden=all|visible|hidden&language` → items je Seite: `uid, pid, title, nav_title, slug, url, doktype, hidden, nav_hide, no_index, no_follow, seo_title, description, canonical, tstamp, crdate, language, has_content (bool)`;
  bei `language > 0` zusätzlich `l10n_parent` (uid der Standardseite; `uid` ist dann die Übersetzungszeile).
  `hidden` filtert die Ergebniszeilen, nicht den Baum: sichtbare Unterseiten einer versteckten Seite erscheinen bei `hidden=visible`.
  `has_content` zählt nur Inhaltselemente, die zum `hidden`-Modus passen (Standard: sichtbare).
- `GET pages/{uid}` → wie oben plus `content_summary: [{uid,colPos,CType,header,hidden}]` (alle Elemente), `og_title, og_description, twitter_title, twitter_description, sitemap_changefreq, sitemap_priority`
- `GET content?page=<uid>&language&hidden` → items: `uid, pid, colPos, CType, header, header_layout, bodytext_text (Klartext, ≤ 2000 Zeichen), bodytext_length, hidden, tstamp, sorting`
- `GET content/{uid}` → `… bodytext (HTML), image_count, link_count`
- `GET news?site&search=&hidden&limit&offset&language` (nur wenn `features.news`) → items: `uid, pid (Ablageordner, ab 1.0.3), title, teaser, datetime, hidden, categories:[{uid,title}], tags:[…], slug, url (falls Detailseite konfiguriert), tstamp`
- `GET news/{uid}` → plus `bodytext (HTML)`, `bodytext_text`
- `GET redirects?search=&limit&offset` (nur wenn `features.redirects`) → items: `uid, source_host, source_path, target, target_statuscode, is_regexp, disabled, hitcount, lasthiton, createdon`
- `GET redirects/{uid}` (nur wenn `features.redirects`, sonst 404 `feature_unavailable`) → dieselben Felder wie ein Listeneintrag; unbekannte uid → 404 `not_found`
- `GET seo/overview?site&language` → `{"pages_total":n,"missing_title":[{uid,title,url}],"missing_description":[…],"noindex":[…],"long_title":[…] (> 60 Zeichen),"long_description":[…] (> 160),"duplicate_titles":[[uid,uid],…]}` (höchstens 100 je Liste)
- `GET search?q=&site&language&limit` (`q` mindestens 2 Zeichen) → Seiten und Inhaltselemente mit Treffer in Titel/Header/Bodytext: `{"q":"<Suchbegriff>","pages":[…],"content":[…],"news":[…]}`
- Gelesen wird nur der Live-Workspace; Versionszeilen aus Workspaces erscheinen nie als eigene Datensätze.

## Schreiben (immer als Backend-Benutzer `_cli_` über den DataHandler, mit `sys_log`-Eintrag „velundo: <Änderung>“; nie löschen)
- `PATCH pages/{uid}` Body: Teilmenge von `title, nav_title, seo_title, description, no_index, no_follow, hidden, canonical` → 200 mit der Seite wie `GET pages/{uid}`.
  Längen: `title`, `nav_title`, `seo_title` ≤ 255 Zeichen, `description` ≤ 5000, `canonical` ≤ 2048 → sonst 422 `too_long`.
- `PATCH content/{uid}` Body: Teilmenge von `header, bodytext (HTML, ≤ max_bodytext_kb), hidden` → 200 mit Element
- `POST content` Body: `page (uid), colPos (int, Standard 0), CType ("text"|"textmedia"|"header"), header, bodytext, hidden (Standard true), after (uid, optional)` → 201 `{"uid":n}`.
  Einsortierung: ohne `after` ans Ende der Spalte (hinter das letzte Element gleicher Seite, gleicher `colPos` und Sprache,
  versteckte eingeschlossen). Mit `after` direkt hinter dieses Element; es muss auf derselben Seite und in derselben Sprache
  liegen (422), seine Spalte wird übernommen – ein abweichend übergebenes `colPos` → 422 `colpos_mismatch`.
- `POST news` (features.news) Body: `title, teaser, bodytext, datetime (ISO), categories:[uid], hidden (Standard true), pid (Storage-Ordner; Standard aus Erweiterungseinstellung)` → 201 `{"uid":n}`.
  Unbekannte Kategorie-uid → 422 `unknown_category` (`details.missing`).
- `PATCH news/{uid}` Body: Teilmenge von `title, teaser, bodytext, datetime, hidden, categories`
- `POST redirects` (features.redirects) Body: `source_host ("*"), source_path (beginnt mit /), target, target_statuscode (301|302|307|308, Standard 301)` → 201 `{"uid":n}`; Konflikt gleicher source → 409.
  `target` muss mit `/` (nicht `//`), `http://`, `https://` oder `t3://page?uid=<uid>` beginnen und darf keine Leerzeichen
  oder Steuerzeichen enthalten → sonst 422 `invalid_target` (gilt auch für PATCH).
- `PATCH redirects/{uid}` Body: Teilmenge von `target, target_statuscode, disabled`
- `POST cache/flush` Body: `{"pages":[uid,…]}` oder `{}` (alle Seiten-Caches) → 200 `{"flushed":"pages"|"all"}`
- Jede Schreibantwort enthält zusätzlich `"before": {…}` mit den vorherigen Werten der geänderten Felder (für den Ist-Vergleich der App).
- HTML in `bodytext` wird in der Erweiterung über die TYPO3-RTE-Konfiguration bereinigt (keine `<script>`, keine Event-Attribute).
- Weist der DataHandler eine Änderung ab → 422 `write_rejected` mit `details.errors: [{message, table, uid}]` (ohne rohe
  Datenbank-Fehlertexte). Einzelne Felder können dann bereits gespeichert sein; die App liest den Datensatz neu.
- Ist der Backend-Benutzer `_cli_` deaktiviert oder abgelaufen → 500 `cli_user_unavailable` mit Hinweis (der Benutzer wird
  nur angelegt, wenn es ihn noch gar nicht gibt).

## Sicherheit
- Nur Felder dieses Vertrags; keine Nutzer-, Dateisystem- oder Konfigurationszugriffe; keine Löschungen; `_velundo`-Pfad
  antwortet ohne Token nur 401 (keine Versionsinfo).
- Die Erweiterung protokolliert jeden Aufruf in `sys_log` (Token-Bezeichnung, Aktion, uid, bei Datensätzen auch die Seite –
  keine Inhalte); 429-Antworten ausgenommen.
