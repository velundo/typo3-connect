<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Api\InfoHandler;

final class VersionTest extends TestCase
{
    /** GET info meldet bei Installation aus einem Path-Repository („dev-main“) die Konstante – sie muss mitwandern. */
    public function testInfoVersionMatchesExtEmconf(): void
    {
        $_EXTKEY = 'velundo_connect';
        $EM_CONF = [];
        require dirname(__DIR__, 2) . '/ext_emconf.php';
        self::assertSame($EM_CONF[$_EXTKEY]['version'], InfoHandler::EXTENSION_VERSION);
    }

    public function testChangelogNamesCurrentVersion(): void
    {
        $changelog = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        self::assertStringContainsString('## ' . InfoHandler::EXTENSION_VERSION . ' ', $changelog);
    }
}
