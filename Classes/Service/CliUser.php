<?php

declare(strict_types=1);

namespace Velundo\Connect\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Velundo\Connect\Http\ApiException;

/**
 * Stellt den Backend-Benutzer `_cli_` im Web-Kontext bereit (wird bei Bedarf angelegt) – für DataHandler und sys_log.
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
            if ($this->recordExists()) {
                throw new ApiException(
                    500,
                    'cli_user_unavailable',
                    'Der Backend-Benutzer „_cli_“ ist deaktiviert oder abgelaufen. Bitte im Backend unter Benutzer aktivieren.'
                );
            }
            $this->createCliUser();
            $user->setBeUserByName(self::USERNAME);
        }
        if (empty($user->user['uid'])) {
            throw new ApiException(500, 'cli_user_unavailable', 'Der Backend-Benutzer „_cli_“ konnte nicht angelegt werden.');
        }
        // Reihenfolge wie CommandLineUserAuthentication::authenticate() im Kern
        if (method_exists($user, 'unpack_uc')) {
            $user->unpack_uc();
        }
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

    /**
     * Legt `_cli_` an wie der Kern es auf der Kommandozeile tut: Admin ohne nutzbares Passwort.
     * Bringt der Kern eine eigene Klasse dafür mit, wird sie verwendet; sonst wird der Datensatz direkt geschrieben.
     */
    private function createCliUser(): void
    {
        $creationClass = 'TYPO3\\CMS\\Core\\Authentication\\CommandLineUserCreation';
        if (class_exists($creationClass) && method_exists($creationClass, 'ensureCliUserExists')) {
            $created = GeneralUtility::makeInstance($creationClass)->ensureCliUserExists();
            if ($created === false) {
                throw new ApiException(500, 'cli_user_unavailable', 'Der Backend-Benutzer „_cli_“ konnte nicht angelegt werden.');
            }
            return;
        }
        $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('BE');
        $password = $hashInstance->getHashedPassword(GeneralUtility::makeInstance(Random::class)->generateRandomHexString(48));
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->insert('be_users', [
                'username' => self::USERNAME,
                'password' => $password,
                'admin' => 1,
                'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
                'crdate' => $GLOBALS['EXEC_TIME'] ?? time(),
            ]);
    }
}
