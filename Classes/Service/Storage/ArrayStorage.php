<?php

declare(strict_types=1);

namespace Velundo\Connect\Service\Storage;

/**
 * Speicher im Arbeitsspeicher – nur für Prüfungen.
 */
final class ArrayStorage implements KeyValueStorageInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
