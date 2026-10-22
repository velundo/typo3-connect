<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiException;

/**
 * Stellt den Backend-Benutzer `_cli_` im Web-Kontext bereit – für DataHandler und sys_log. Angelegt wird er nicht hier
 * (keine Benutzeranlage aus einer Webanfrage), sondern von TYPO3 beim ersten Kommandozeilenaufruf.
 * CommandLineUserAuthentication ist nur auf der Kommandozeile erlaubt; hier wird der Datensatz deshalb über die
 * normale BackendUserAuthentication geladen – ohne Sitzung, ohne Cookie.
 */
final class CliUser
{
    public const USERNAME = '_cli_';

    private ?BackendUserAuthentication $user = null;

    public function get(): BackendUserAuthentication
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        // setBeUserByName() findet nur aktive Benutzer (nicht gelöscht, nicht deaktiviert, nicht abgelaufen)
        $user->setBeUserByName(self::USERNAME);
        if (empty($user->user['uid'])) {
            // Aus einer Webanfrage wird kein Backend-Benutzer angelegt (seit 1.1.0): Den Datensatz „_cli_“ erzeugt TYPO3 beim
            // ersten Kommandozeilenaufruf von selbst – spätestens bei `velundo:token create`
            throw new ApiException(
                500,
                'cli_user_unavailable',
                $this->recordExists()
                    ? 'Der Backend-Benutzer „_cli_“ ist deaktiviert oder abgelaufen. Bitte im Backend unter Benutzer aktivieren.'
                    : 'Der Backend-Benutzer „_cli_“ fehlt. TYPO3 legt ihn beim ersten Kommandozeilenaufruf an (z. B. vendor/bin/typo3 velundo:token list).'
            );
        }
        // Wie CommandLineUserAuthentication::authenticate() im Kern – unpack_uc() ist dort geschützt (TYPO3 14) und von
        // außen nicht aufrufbar (method_exists meldet auch geschützte Methoden); backendSetUC() setzt bei leerem uc die Vorgaben
        $user->fetchGroupData();
        $user->backendSetUC();
        if ($user->workspace !== 0) {
            // Schreibzugriffe erfolgen immer im Live-Workspace
            $user->setWorkspace(0);
        }
        $GLOBALS['BE_USER'] = $user;
        if (!isset($GLOBALS['LANG'])) {
            $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($user);
        }
        return $this->user = $user;
    }

    /** Gibt es den Datensatz `_cli_` (auch deaktiviert oder abgelaufen, aber nicht gelöscht)? */
    private function recordExists(): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $count = $queryBuilder->count('uid')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter(self::USERNAME)))
            ->executeQuery()
            ->fetchOne();
        return (int)$count > 0;
    }

}
