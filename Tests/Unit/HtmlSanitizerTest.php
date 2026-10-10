<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Service\HtmlSanitizer;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testRemovesScriptsAndEventHandlers(): void
    {
        $html = '<p onclick="alert(1)">Hallo <b>Welt</b><script>alert(2)</script></p><SCRIPT src=x.js></SCRIPT><img src="a.png" ONERROR=alert(3)>';
        $clean = $this->sanitizer->sanitize($html);
        self::assertStringNotContainsString('<script', strtolower($clean));
        self::assertStringNotContainsString('alert', $clean);
        self::assertStringNotContainsString('onclick', strtolower($clean));
        self::assertStringNotContainsString('onerror', strtolower($clean));
        self::assertStringContainsString('<b>Welt</b>', $clean);
    }

    public function testRemovesJavascriptLinks(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a><a href=" JaVaScRiPt:void(0)">y</a><a href="https://example.de/">ok</a>');
        self::assertStringNotContainsString('javascript', strtolower($clean));
        self::assertStringContainsString('href="https://example.de/"', $clean);
    }

    public function testRemovesIframesAndStyle(): void
    {
        $clean = $this->sanitizer->sanitize('<p>Text</p><iframe src="https://example.de"></iframe><style>p{color:red}</style><object data="x"></object>');
        self::assertStringNotContainsString('<iframe', $clean);
        self::assertStringNotContainsString('<style', $clean);
        self::assertStringNotContainsString('<object', $clean);
        self::assertStringContainsString('<p>Text</p>', $clean);
    }

    public function testKeepsRegularMarkup(): void
    {
        $html = '<h2>Überschrift</h2><p>Absatz mit <a href="/seite">Link</a> und <strong>fett</strong>.</p><ul><li>eins</li></ul>';
        self::assertSame($html, $this->sanitizer->preClean($html));
    }

    public function testKeepsRunningTextThatLooksLikeAnEventAttribute(): void
    {
        // Review Nr. 2: „online=Version“ im Fließtext wurde als Ereignis-Attribut gelöscht
        $html = '<p>Die online=Version ist da. Bitte <b>anmelden</b>: Konto=offen und onboarding=heute.</p>';
        self::assertSame($html, $this->sanitizer->preClean($html));
        self::assertStringContainsString('online=Version ist da', $this->sanitizer->sanitize($html));
        // Innerhalb von Tags wird weiterhin bereinigt – auch wenn danach Fließtext mit „on…=“ folgt
        $clean = $this->sanitizer->preClean('<p onmouseover="x()">online=Version</p><a href="/a" onclick=alert(1)>online=1</a>');
        self::assertSame('<p>online=Version</p><a href="/a">online=1</a>', $clean);
    }

    public function testToText(): void
    {
        self::assertSame('Hallo Welt – zwei', HtmlSanitizer::toText("<p>Hallo</p><p>Welt &ndash;\n zwei</p>"));
        $long = HtmlSanitizer::toText('<p>' . str_repeat('a', 3000) . '</p>', 2000);
        self::assertSame(2000, mb_strlen($long));
        self::assertSame('…', mb_substr($long, -1));
        self::assertSame('', HtmlSanitizer::toText(''));
        // ungültiges UTF-8 (z. B. alte Datenbankinhalte) darf den Vorschautext nicht leeren
        self::assertSame('Text ? Ende', HtmlSanitizer::toText("<p>Text \xFF Ende</p>"));
    }
}
