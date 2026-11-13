#!/usr/bin/env bash
# Richtet eine frisch per Composer installierte TYPO3-Instanz für die Prüfung ein (SQLite, eine Site, drei Tokens),
# startet den PHP-Server und prüft die Schnittstelle mit api-check.php: einmal alle Endpunkte, dann ein widerrufenes
# Token, zuletzt die HTTPS-Pflicht.
#
#   Tests/Integration/run.sh <Verzeichnis der TYPO3-Installation> [<Port>]
#
# Umgebung: PHP (Standard php), VL_TYPO3 (erwartete Hauptversion, optional).
set -euo pipefail

EXT="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="$(cd "$1" && pwd)"
PORT="${2:-8080}"
PHP="${PHP:-php}"
cd "$SITE"

typo3() { "$PHP" vendor/bin/typo3 --no-ansi "$@"; }
setting() {
  "$PHP" -r '$f = "config/system/settings.php"; $c = require $f; $c["EXTENSIONS"]["velundo_connect"][$argv[1]] = $argv[2]; file_put_contents($f, "<?php\nreturn " . var_export($c, true) . ";\n");' "$1" "$2"
  typo3 cache:flush > /dev/null
}

echo "::group::TYPO3 einrichten"
TYPO3_DB_DRIVER=sqlite TYPO3_SETUP_ADMIN_USERNAME=admin TYPO3_SETUP_ADMIN_PASSWORD='Ci-Admin-1234!' \
TYPO3_SETUP_ADMIN_EMAIL=ci@example.org TYPO3_PROJECT_NAME=CI TYPO3_SERVER_TYPE=other \
TYPO3_SETUP_CREATE_SITE="http://127.0.0.1:$PORT/" \
  typo3 setup --no-interaction --force
typo3 extension:setup
echo "::endgroup::"

# Token erzeugen; Ausgabe „<Kennung> <Token>“
token() {
  local out
  out="$(typo3 velundo:token create "$@")"
  printf '%s %s\n' "$(printf '%s' "$out" | grep -oE 'Kennung [0-9a-f]+' | head -1 | cut -d' ' -f2)" \
    "$(printf '%s\n' "$out" | grep -E '^[A-Za-z0-9_-]{30,}$' | head -1)"
}
read -r PLAIN_ID PLAIN < <(token "CI ohne Rechte")
read -r _ PUBLISH < <(token "CI veröffentlichen" --allow-publish)
read -r _ FULL < <(token "CI alle Rechte" --allow-publish --allow-live-edit --allow-external-redirect)
test -n "$PLAIN" && test -n "$PUBLISH" && test -n "$FULL" && test -n "$PLAIN_ID"
typo3 velundo:token list

setting require_https 0
"$PHP" -S "127.0.0.1:$PORT" -t public public/index.php > "$SITE/server.log" 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2> /dev/null || true' EXIT
curl -s -o /dev/null --retry 30 --retry-connrefused --retry-delay 1 "http://127.0.0.1:$PORT/"

DB="$("$PHP" -r '$c = require "config/system/settings.php"; echo $c["DB"]["Connections"]["Default"]["path"];')"
export VL_BASE="http://127.0.0.1:$PORT/_velundo/v1/" VL_TOKEN="$PLAIN" VL_TOKEN_PUBLISH="$PUBLISH" VL_TOKEN_FULL="$FULL" VL_DB_DSN="sqlite:$DB"

echo "::group::Endpunkte"
VL_MODE=api "$PHP" "$EXT/Tests/Integration/api-check.php"
echo "::endgroup::"

typo3 velundo:token revoke "$PLAIN_ID"
VL_MODE=revoked "$PHP" "$EXT/Tests/Integration/api-check.php"

setting require_https 1
VL_MODE=https VL_TOKEN="$FULL" "$PHP" "$EXT/Tests/Integration/api-check.php"
