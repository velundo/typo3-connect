<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Protokolliert jeden Aufruf in sys_log (Typ 4 = Erweiterung) als Backend-Benutzer `_cli_`:
 * Token-Bezeichnung, Aktion und uid – keine Inhalte.
 */
final class LogService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TYPE_EXTENSION = 4;

    public function __construct(private readonly CliUser $cliUser) {}

    public function log(string $message, string $tokenName, string $table = '', int $uid = 0, int $pid = -1): void
    {
        $details = 'velundo: ' . self::clean($message) . ' – Token „' . self::clean($tokenName) . '“';
        try {
            $this->cliUser->get()->writelog(self::TYPE_EXTENSION, 0, 0, 0, $details, [], $table, $uid, '', $pid);
        } catch (\Throwable $e) {
            $this->logger?->warning('velundo: Eintrag in sys_log fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private static function clean(string $text): string
    {
        $text = str_replace('%', '', $text);
        $text = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        return mb_substr($text, 0, 200);
    }
}
