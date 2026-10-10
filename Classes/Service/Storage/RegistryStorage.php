<?php

declare(strict_types=1);

namespace Velundo\Connect\Service\Storage;

use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ablage in sys_registry, Namensraum velundo_connect.
 */
final class RegistryStorage implements KeyValueStorageInterface
{
    public const NAMESPACE = 'velundo_connect';

    private ?Registry $registry = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->registry()->get(self::NAMESPACE, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->registry()->set(self::NAMESPACE, $key, $value);
    }

    public function remove(string $key): void
    {
        $this->registry()->remove(self::NAMESPACE, $key);
    }

    private function registry(): Registry
    {
        return $this->registry ??= GeneralUtility::makeInstance(Registry::class);
    }
}
