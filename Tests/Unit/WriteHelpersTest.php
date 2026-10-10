<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Api\RedirectsHandler;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ChangeSet;
use Velundo\Connect\Service\DataHandlerService;

/**
 * Helfer der Schreibpfade, die ohne TYPO3 prüfbar sind: ChangeSet, Umleitungsziele, Fehlertexte des DataHandlers.
 */
final class WriteHelpersTest extends TestCase
{
    public function testChangeSetCollectsDataAndBefore(): void
    {
        $changes = new ChangeSet();
        $changes->set('title', 'Neu', 'Alt');
        $changes->set('nav_title', null, 'bleibt');
        $changes->set('canonical', '/x', '', 'canonical_link');
        $changes->flag('hidden', true, false);
        $changes->flag('no_index', null, true);
        self::assertSame(['title' => 'Neu', 'canonical_link' => '/x', 'hidden' => 1], $changes->data());
        self::assertSame(['title' => 'Alt', 'canonical' => '', 'hidden' => false], $changes->before());
        self::assertSame('title, canonical, hidden', $changes->fieldList());
        $changes->requireAny(['title']);
    }

    public function testChangeSetRequiresAtLeastOneField(): void
    {
        $changes = new ChangeSet();
        $changes->set('title', null, 'x');
        try {
            $changes->requireAny(['title', 'hidden']);
            self::fail('empty_body erwartet');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('empty_body', $e->errorCode);
            self::assertSame(['title', 'hidden'], $e->details['allowed']);
        }
    }

    public function testRedirectTargetPattern(): void
    {
        foreach (['/', '/neu/seite', 'https://example.de/x?y=1', 'http://example.de', 't3://page?uid=12', 't3://page?uid=12#abschnitt'] as $ok) {
            self::assertTrue(RedirectsHandler::isValidTarget($ok), $ok);
        }
        foreach (['seite', 'javascript:alert(1)', 'ftp://example.de', 't3://page?uid=', 't3://file?uid=1', '//example.de', "/pfad\nmit umbruch", 'https:/example.de'] as $bad) {
            self::assertFalse(RedirectsHandler::isValidTarget($bad), $bad);
        }
    }

    public function testDataHandlerErrorsAreReducedToTableAndUid(): void
    {
        $errors = DataHandlerService::describeErrors([
            "[1.2.1]: SQL error: 'Data too long for column header at row 1 (SQLSTATE 22001)' (tt_content:15)",
            '[1.1.1]: Attempt to insert record on page 7 without permission (pages:7)',
            'Irgendein Hinweis ohne Zusatz',
        ]);
        self::assertCount(3, $errors);
        self::assertSame('Datenbankfehler beim Speichern', $errors[0]['message']);
        self::assertSame('tt_content', $errors[0]['table']);
        self::assertSame('15', $errors[0]['uid']);
        self::assertSame('Attempt to insert record on page 7 without permission', $errors[1]['message']);
        self::assertSame('pages', $errors[1]['table']);
        self::assertSame('Irgendein Hinweis ohne Zusatz', $errors[2]['message']);
        self::assertNull($errors[2]['table']);
        self::assertStringNotContainsString('SQLSTATE', json_encode($errors));
        self::assertStringNotContainsString('Data too long', json_encode($errors));
    }
}
