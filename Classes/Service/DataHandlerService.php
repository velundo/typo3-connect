<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiException;

/**
 * Alle Schreibzugriffe laufen über den DataHandler als Backend-Benutzer `_cli_` –
 * mit Verlauf, Referenzindex, Cache-Leerung und dem Protokoll des Kerns. Es wird nie gelöscht.
 */
final class DataHandlerService
{
    public function __construct(private readonly CliUser $cliUser) {}

    /**
     * Ändert Felder eines Datensatzes.
     *
     * @param array<string, mixed> $fields
     */
    public function update(string $table, int $uid, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->run([$table => [$uid => $fields]]);
    }

    /**
     * Legt einen Datensatz an; `pid` steht in $fields. Mit $afterUid wird hinter diesem Datensatz einsortiert.
     *
     * @param array<string, mixed> $fields
     */
    public function create(string $table, array $fields, ?int $afterUid = null): int
    {
        if ($afterUid !== null && $afterUid > 0) {
            $fields['pid'] = -$afterUid;
        }
        $dataHandler = $this->run([$table => ['NEW_velundo' => $fields]]);
        $uid = (int)($dataHandler->substNEWwithIDs['NEW_velundo'] ?? 0);
        if ($uid <= 0) {
            throw new ApiException(500, 'write_failed', 'Der Datensatz wurde nicht angelegt.');
        }
        return $uid;
    }

    /**
     * Fehler des DataHandlers für die Antwort: Kennziffern und rohe SQL-Fehlertexte entfernt, Tabelle/uid aus dem
     * Zusatz „(tabelle:uid)“ ausgelesen, Text gekürzt.
     *
     * @param list<mixed> $errorLog
     * @return list<array{message: string, table: ?string, uid: ?string}>
     */
    public static function describeErrors(array $errorLog): array
    {
        $result = [];
        foreach ($errorLog as $entry) {
            $text = trim((string)preg_replace('/^\[[\d.]+\]:\s*/', '', (string)$entry));
            $table = null;
            $uid = null;
            if (preg_match('/\(([a-z0-9_]+):([A-Za-z0-9_]+)\)\s*$/', $text, $m) === 1) {
                $table = $m[1];
                $uid = $m[2];
                $text = trim(substr($text, 0, -strlen($m[0])));
            }
            if (preg_match('/SQL|SQLSTATE|Doctrine|database|Datenbank/i', $text) === 1) {
                $text = 'Datenbankfehler beim Speichern';
            }
            $text = (string)preg_replace('/\s+/', ' ', $text);
            $result[] = [
                'message' => mb_substr($text, 0, 200),
                'table' => $table,
                'uid' => $uid,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     */
    private function run(array $dataMap): DataHandler
    {
        $user = $this->cliUser->get();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, [], $user);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            throw ApiException::validation(
                'TYPO3 hat die Änderung ganz oder teilweise abgewiesen; einzelne Felder können bereits gespeichert sein.',
                ['errors' => self::describeErrors($dataHandler->errorLog)],
                'write_rejected'
            );
        }
        return $dataHandler;
    }
}
