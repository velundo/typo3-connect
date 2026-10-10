<?php

declare(strict_types=1);

namespace Velundo\Connect\Service\Storage;

/**
 * Kleiner Schlüssel-Wert-Speicher für Tokens und Zähler. Im Betrieb sys_registry, in Prüfungen ein Feld.
 */
interface KeyValueStorageInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Schreibt $value nur, wenn der Eintrag derzeit genau $expected enthält (null = es gibt keinen Eintrag).
     * Liefert false, wenn zwischenzeitlich jemand anderes geschrieben hat – der Aufrufer liest dann neu.
     */
    public function compareAndSet(string $key, mixed $expected, mixed $value): bool;
}
