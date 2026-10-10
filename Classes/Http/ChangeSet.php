<?php

declare(strict_types=1);

namespace Velundo\Connect\Http;

/**
 * Sammelt bei PATCH die zu schreibenden Felder (Datenbanknamen) und die vorherigen Werte (`before`, API-Namen).
 * Felder ohne Wert (null) werden übergangen.
 */
final class ChangeSet
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $before = [];

    /** Nimmt $value auf, wenn es nicht null ist; $dbField nur, wenn der Spaltenname vom API-Feld abweicht. */
    public function set(string $apiField, mixed $value, mixed $before, ?string $dbField = null): void
    {
        if ($value === null) {
            return;
        }
        $this->data[$dbField ?? $apiField] = $value;
        $this->before[$apiField] = $before;
    }

    /** Wie set() für Schalter: true/false wird als 1/0 geschrieben. */
    public function flag(string $apiField, ?bool $value, bool $before, ?string $dbField = null): void
    {
        $this->set($apiField, $value === null ? null : ($value ? 1 : 0), $before, $dbField);
    }

    /** 422 `empty_body`, wenn kein Feld einen Wert enthält. @param list<string> $allowed */
    public function requireAny(array $allowed): void
    {
        if ($this->data === []) {
            throw ApiException::validation('Keines der Felder enthält einen Wert.', ['allowed' => $allowed], 'empty_body');
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function before(): array
    {
        return $this->before;
    }

    /** Geänderte API-Felder für das Protokoll, z. B. „title, hidden“. */
    public function fieldList(): string
    {
        return implode(', ', array_keys($this->before));
    }
}
