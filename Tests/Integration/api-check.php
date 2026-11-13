<?php

declare(strict_types=1);

/*
 * Prüft die Schnittstelle einer laufenden TYPO3-Installation mit der Erweiterung über HTTP (CI, Job „TYPO3 …“).
 * Erwartet eine frische Installation mit einer Site (typo3 setup --create-site) und drei Tokens:
 *
 *   VL_BASE           Endpunktbasis, z. B. http://127.0.0.1:8080/_velundo/v1/
 *   VL_TOKEN          Token ohne Zusatzrechte
 *   VL_TOKEN_PUBLISH  Token mit --allow-publish
 *   VL_TOKEN_FULL     Token mit --allow-publish --allow-live-edit --allow-external-redirect
 *   VL_TYPO3          erwartete Hauptversion von TYPO3 (z. B. 13), optional
 *   VL_DB_DSN         PDO-DSN der Datenbank (optional): prüft die Einträge in sys_log
 *   VL_MODE           api (Standard), https (require_https aktiv: 403 https_required) oder revoked (Token widerrufen)
 *
 *   php Tests/Integration/api-check.php
 */

$base = rtrim((string)getenv('VL_BASE'), '/') . '/';
$token = (string)getenv('VL_TOKEN');
$mode = getenv('VL_MODE') ?: 'api';
if ($base === '/' || $token === '') {
    fwrite(STDERR, "VL_BASE und VL_TOKEN setzen.\n");
    exit(2);
}

$failed = 0;
$passed = 0;

/** @return array{status: int, body: mixed, headers: array<string, string>} */
function call(string $method, string $path, ?string $token, ?array $body = null, array $extraHeaders = []): array
{
    global $base;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge($headers, $extraHeaders)),
        'content' => $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : '',
        'ignore_errors' => true,
        'timeout' => 60,
    ]]);
    $raw = @file_get_contents($base . $path, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    $parsed = [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
            $parsed = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
    }
    $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => $parsed];
}

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok      $name\n";
        return;
    }
    $failed++;
    $text = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "FEHLER  $name: " . mb_substr((string)$text, 0, 1500) . "\n";
}

function errorCode(array $r): string
{
    return is_array($r['body']) ? (string)($r['body']['error']['code'] ?? '') : '';
}

// Ohne gültiges Token antwortet jeder Pfad mit 401 – in jedem Modus
$r = call('GET', 'info', null);
check('ohne Token 401 unauthorized', $r['status'] === 401 && errorCode($r) === 'unauthorized', $r);
check('Kopf X-Velundo-Api: 1 und Cache-Control: no-store', ($r['headers']['x-velundo-api'] ?? '') === '1' && str_contains($r['headers']['cache-control'] ?? '', 'no-store'), $r['headers']);
$r = call('GET', 'info', 'falsch-' . bin2hex(random_bytes(8)));
check('falsches Token 401', $r['status'] === 401, $r);

if ($mode === 'revoked') {
    $r = call('GET', 'info', $token);
    check('widerrufenes Token 401', $r['status'] === 401 && errorCode($r) === 'unauthorized', $r);
} elseif ($mode === 'https') {
    $r = call('GET', 'info', $token);
    check('gültiges Token über http mit require_https: 403 https_required', $r['status'] === 403 && errorCode($r) === 'https_required', $r);
} else {
    runApiChecks($token);
}

echo sprintf("%d Prüfungen bestanden, %d fehlgeschlagen (%s)\n", $passed, $failed, $mode);
exit($failed === 0 ? 0 : 1);

function runApiChecks(string $token): void
{
    $publish = (string)getenv('VL_TOKEN_PUBLISH');
    $full = (string)getenv('VL_TOKEN_FULL');
    $root = dirname(__DIR__, 2);

    // Version aus ext_emconf.php
    $_EXTKEY = 'velundo_connect';
    $EM_CONF = [];
    require $root . '/ext_emconf.php';
    $version = $EM_CONF[$_EXTKEY]['version'];

    // --- Lesen
    $r = call('GET', 'info', $token, null, ['X-Velundo-Token: ignoriert']);
    $info = is_array($r['body']) ? $r['body'] : [];
    check('GET info 200', $r['status'] === 200, $r);
    check("GET info nennt die Erweiterung $version", ($info['extension'] ?? null) === $version, $info['extension'] ?? null);
    $major = (string)getenv('VL_TYPO3');
    check('GET info nennt TYPO3 ' . ($major ?: '(beliebig)'), preg_match('/^\d+\.\d+/', (string)($info['typo3'] ?? '')) === 1
        && ($major === '' || str_starts_with((string)$info['typo3'], $major . '.')), $info['typo3'] ?? null);
    check('GET info nennt PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, str_starts_with((string)($info['php'] ?? ''), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.'), $info['php'] ?? null);
    check('GET info: eine Site mit Sprache 0', ($info['sites'][0]['identifier'] ?? '') !== '' && ($info['sites'][0]['languages'][0]['id'] ?? null) === 0, $info['sites'] ?? null);
    check('GET info: Funktionen seo, redirects, news erkannt', ($info['features']['seo'] ?? false) === true && ($info['features']['redirects'] ?? false) === true && ($info['features']['news'] ?? false) === true, $info['features'] ?? null);
    check('GET info: Token ohne Zusatzrechte', ($info['token']['scopes'] ?? null) === [] && ($info['token']['name'] ?? '') === 'CI ohne Rechte', $info['token'] ?? null);
    $r = call('GET', 'info', $full);
    check('GET info: Token mit allen Zusatzrechten', is_array($r['body']) && ($r['body']['token']['scopes'] ?? null) === ['publish', 'live_edit', 'external_redirect'], $r['body']['token'] ?? $r);
    $r = call('GET', 'info', null, null, ['X-Velundo-Token: ' . $token]);
    check('Token ersatzweise im Kopf X-Velundo-Token', $r['status'] === 200, $r);

    $r = call('GET', 'system', $token);
    $keys = array_column(is_array($r['body']) ? ($r['body']['extensions'] ?? []) : [], 'key');
    check('GET system: Erweiterungen ohne Inventarliste, mit velundo_connect', $r['status'] === 200 && in_array('velundo_connect', $keys, true)
        && array_diff($keys, ['core', 'seo', 'redirects', 'news', 'workspaces', 'scheduler', 'velundo_connect']) === [], $r);

    $r = call('GET', 'pages', $token);
    $page = is_array($r['body']) ? ($r['body']['items'][0] ?? []) : [];
    $pageUid = (int)($page['uid'] ?? 0);
    check('GET pages: Startseite mit absoluter Adresse', $r['status'] === 200 && $pageUid > 0 && str_starts_with((string)($page['url'] ?? ''), 'http'), $r);
    $r = call('GET', "pages/$pageUid", $token);
    check('GET pages/{uid} mit content_summary und SEO-Feldern', $r['status'] === 200 && is_array($r['body']['content_summary'] ?? null) && array_key_exists('og_title', $r['body']), $r);
    $r = call('GET', 'pages/999999', $token);
    check('unbekannte Seite 404 not_found', $r['status'] === 404 && errorCode($r) === 'not_found', $r);
    $r = call('GET', 'gibt-es-nicht', $token);
    check('unbekannter Pfad 404', $r['status'] === 404, $r);

    // --- Seiten schreiben
    $r = call('PATCH', "pages/$pageUid", $token, ['description' => 'CI Beschreibung', 'seo_title' => 'CI SEO-Titel']);
    check('PATCH pages: SEO-Felder ohne Zusatzrecht, mit before', $r['status'] === 200 && ($r['body']['description'] ?? '') === 'CI Beschreibung'
        && is_array($r['body']['before'] ?? null) && array_key_exists('description', $r['body']['before']), $r);
    $r = call('PATCH', "pages/$pageUid", $token, ['title' => 'CI Titel']);
    check('PATCH pages: Titel einer sichtbaren Seite ohne live_edit 403 scope_live_edit', $r['status'] === 403 && errorCode($r) === 'scope_live_edit', $r);
    $r = call('PATCH', "pages/$pageUid", $full, ['title' => 'CI Titel']);
    check('PATCH pages: Titel mit live_edit', $r['status'] === 200 && ($r['body']['title'] ?? '') === 'CI Titel', $r);
    $r = call('PATCH', "pages/$pageUid", $full, ['hidden' => true]);
    check('PATCH pages: Startpunkt der Site verbergen 422 site_root', $r['status'] === 422 && errorCode($r) === 'site_root', $r);
    $r = call('PATCH', "pages/$pageUid", $token, ['canonical' => 'javascript:alert(1)']);
    check('PATCH pages: canonical mit javascript: 422 invalid_canonical', $r['status'] === 422 && errorCode($r) === 'invalid_canonical', $r);

    // --- Inhaltselemente
    $r = call('POST', 'content', $token, ['page' => $pageUid, 'CType' => 'text', 'header' => 'CI Entwurf', 'bodytext' => '<p>Hallo <strong>Welt</strong></p><script>alert(1)</script><p onclick="x()">Zwei</p>']);
    $uid = (int)($r['body']['uid'] ?? 0);
    check('POST content: Entwurf 201', $r['status'] === 201 && $uid > 0, $r);
    $r = call('GET', "content/$uid", $token);
    $body = (string)($r['body']['bodytext'] ?? '');
    check('GET content/{uid}: verborgen, HTML bereinigt', $r['status'] === 200 && ($r['body']['hidden'] ?? null) === true
        && str_contains($body, '<strong>Welt</strong>') && !str_contains($body, '<script') && !str_contains($body, 'onclick'), $r);
    $r = call('POST', 'content', $token, ['page' => $pageUid, 'CType' => 'text', 'header' => 'CI Zweites', 'bodytext' => '<p>Zwei</p>', 'after' => $uid]);
    $second = (int)($r['body']['uid'] ?? 0);
    check('POST content mit after 201', $r['status'] === 201 && $second > 0, $r);
    $r = call('GET', "content?page=$pageUid&hidden=all", $token);
    $order = array_column(is_array($r['body']) ? ($r['body']['items'] ?? []) : [], 'uid');
    check('GET content: Reihenfolge nach after', $r['status'] === 200 && array_search($uid, $order, true) !== false && array_search($second, $order, true) === array_search($uid, $order, true) + 1, $order ?: $r);
    $r = call('PATCH', "content/$uid", $token, ['header' => 'CI Entwurf geändert']);
    check('PATCH content: verborgenes Element ohne Zusatzrecht', $r['status'] === 200 && ($r['body']['header'] ?? '') === 'CI Entwurf geändert' && ($r['body']['before']['header'] ?? '') === 'CI Entwurf', $r);
    $r = call('PATCH', "content/$uid", $token, ['hidden' => false]);
    check('PATCH content: sichtbar schalten ohne publish 403 scope_publish', $r['status'] === 403 && errorCode($r) === 'scope_publish', $r);
    $r = call('PATCH', "content/$uid", $publish, ['hidden' => false]);
    check('PATCH content: sichtbar schalten mit publish', $r['status'] === 200 && ($r['body']['hidden'] ?? null) === false, $r);
    $r = call('PATCH', "content/$uid", $publish, ['header' => 'CI live']);
    check('PATCH content: sichtbares Element ohne live_edit 403 scope_live_edit', $r['status'] === 403 && errorCode($r) === 'scope_live_edit', $r);
    $r = call('PATCH', "content/$uid", $full, ['header' => 'CI live']);
    check('PATCH content: sichtbares Element mit live_edit', $r['status'] === 200 && ($r['body']['header'] ?? '') === 'CI live', $r);
    $r = call('POST', 'content', $token, ['page' => $pageUid, 'CType' => 'html', 'header' => 'x']);
    check('POST content: unzulässiger CType 422', $r['status'] === 422, $r);

    // --- Suche und SEO
    $r = call('GET', 'search?q=CI+live', $token);
    check('GET search findet das Inhaltselement', $r['status'] === 200 && in_array($uid, array_column($r['body']['content'] ?? [], 'uid'), true), $r);
    $r = call('GET', 'seo/overview', $token);
    check('GET seo/overview', $r['status'] === 200 && ($r['body']['pages_total'] ?? 0) >= 1, $r);

    // --- Umleitungen
    $r = call('POST', 'redirects', $token, ['source_host' => '*', 'source_path' => '/ci-alt', 'target' => '/']);
    $redirect = (int)($r['body']['uid'] ?? 0);
    check('POST redirects: interne Umleitung 201', $r['status'] === 201 && $redirect > 0, $r);
    $r = call('POST', 'redirects', $token, ['source_host' => '*', 'source_path' => '/ci-alt', 'target' => '/']);
    check('POST redirects: gleiche Quelle 409', $r['status'] === 409, $r);
    $r = call('POST', 'redirects', $token, ['source_host' => '*', 'source_path' => '/ci-extern', 'target' => 'https://example.org/']);
    check('POST redirects: fremder Host ohne Recht 403 scope_external_redirect', $r['status'] === 403 && errorCode($r) === 'scope_external_redirect', $r);
    $r = call('POST', 'redirects', $full, ['source_host' => '*', 'source_path' => '/ci-extern', 'target' => 'https://example.org/']);
    check('POST redirects: fremder Host mit Recht 201', $r['status'] === 201, $r);
    $r = call('POST', 'redirects', $token, ['source_host' => '*', 'source_path' => '/ci-js', 'target' => 'javascript:alert(1)']);
    check('POST redirects: javascript: 422 invalid_target', $r['status'] === 422 && errorCode($r) === 'invalid_target', $r);
    $r = call('PATCH', "redirects/$redirect", $token, ['disabled' => true]);
    check('PATCH redirects: deaktivieren', $r['status'] === 200 && ($r['body']['disabled'] ?? null) === true, $r);
    $r = call('GET', "redirects/$redirect", $token);
    check('GET redirects/{uid}', $r['status'] === 200 && ($r['body']['source_path'] ?? '') === '/ci-alt', $r);

    // --- Nachrichten (EXT:news, Ablage auf der Startseite)
    $r = call('POST', 'news', $token, ['title' => 'CI Nachricht', 'teaser' => 'Kurz', 'bodytext' => '<p>Text</p>', 'datetime' => '2026-11-01T10:00:00+01:00', 'pid' => $pageUid]);
    $news = (int)($r['body']['uid'] ?? 0);
    check('POST news: Entwurf 201', $r['status'] === 201 && $news > 0, $r);
    $r = call('GET', "news/$news", $token);
    check('GET news/{uid}: verborgen, Datum übernommen', $r['status'] === 200 && ($r['body']['hidden'] ?? null) === true && str_starts_with((string)($r['body']['datetime'] ?? ''), '2026-11-01T'), $r);
    $r = call('PATCH', "news/$news", $token, ['hidden' => false]);
    check('PATCH news: veröffentlichen ohne publish 403 scope_publish', $r['status'] === 403 && errorCode($r) === 'scope_publish', $r);
    $r = call('POST', 'news', $token, ['title' => 'CI', 'datetime' => '1.11.2026', 'pid' => $pageUid]);
    check('POST news: Datum nicht nach ISO 8601 422 invalid_type', $r['status'] === 422 && errorCode($r) === 'invalid_type', $r);
    $r = call('GET', 'news?hidden=all', $token);
    check('GET news listet die Nachricht', $r['status'] === 200 && in_array($news, array_column($r['body']['items'] ?? [], 'uid'), true), $r);

    // --- Cache
    $r = call('POST', 'cache/flush', $token, ['pages' => [$pageUid]]);
    check('POST cache/flush für eine Seite', $r['status'] === 200 && ($r['body']['flushed'] ?? '') === 'pages', $r);
    $r = call('POST', 'cache/flush', $token, []);
    check('POST cache/flush für alle Seiten', $r['status'] === 200 && ($r['body']['flushed'] ?? '') === 'all', $r);

    // --- Protokoll in sys_log
    $dsn = (string)getenv('VL_DB_DSN');
    if ($dsn !== '') {
        $pdo = new PDO($dsn, getenv('VL_DB_USER') ?: null, getenv('VL_DB_PASS') ?: null);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM sys_log WHERE details LIKE 'velundo: %'")->fetchColumn();
        $leak = (int)$pdo->query("SELECT COUNT(*) FROM sys_log WHERE details LIKE 'velundo: %' AND (details LIKE '%Hallo%' OR details LIKE '%CI Beschreibung%')")->fetchColumn();
        check('sys_log: Einträge „velundo: …“ ohne Inhalte', $count >= 10 && $leak === 0, "Einträge $count, mit Inhalt $leak");
    }
}
