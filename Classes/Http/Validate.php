<?php

declare(strict_types=1);

namespace Velundo\Connect\Http;

/**
 * Typprüfung einzelner Felder des JSON-Rumpfs. Fehlende Felder ergeben null; falsche Typen 422.
 */
final class Validate
{
    /** @param array<string, mixed> $body */
    public static function string(array $body, string $key, int $maxLength = 10000): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        $value = $body[$key];
        if (!is_string($value)) {
            throw self::wrongType($key, 'eine Zeichenkette');
        }
        if (mb_strlen($value) > $maxLength) {
            throw ApiException::validation(sprintf('Feld „%s“ darf höchstens %d Zeichen lang sein.', $key, $maxLength), ['field' => $key, 'max_length' => $maxLength], 'too_long');
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ApiException::validation(sprintf('Feld „%s“ ist kein gültiges UTF-8.', $key), ['field' => $key]);
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    public static function bool(array $body, string $key): ?bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        $value = $body[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool)(int)$value;
        }
        throw self::wrongType($key, 'true oder false');
    }

    /** @param array<string, mixed> $body */
    public static function int(array $body, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        $value = $body[$key];
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value)) {
            throw self::wrongType($key, 'eine ganze Zahl');
        }
        if ($value < $min || $value > $max) {
            throw ApiException::validation(sprintf('Feld „%s“ muss zwischen %d und %d liegen.', $key, $min, $max), ['field' => $key, 'min' => $min, 'max' => $max]);
        }
        return $value;
    }

    /**
     * Liste ganzer Zahlen (z. B. Kategorien).
     *
     * @param array<string, mixed> $body
     * @return list<int>|null
     */
    public static function intList(array $body, string $key): ?array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        $value = $body[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw self::wrongType($key, 'eine Liste ganzer Zahlen');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item <= 0) {
                throw self::wrongType($key, 'eine Liste ganzer Zahlen größer 0');
            }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    /** Datum, optional Uhrzeit (T oder Leerzeichen), optional Sekunden/Bruchteile, optional Z oder Versatz. */
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * ISO-8601-Zeitpunkt als Unix-Zeit. Nur dieses Format – keine relativen Angaben wie „tomorrow“,
     * keine deutschen Datumsschreibweisen.
     *
     * @param array<string, mixed> $body
     */
    public static function datetime(array $body, string $key): ?int
    {
        $value = self::string($body, $key, 64);
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match(self::ISO_8601, $value) !== 1) {
            throw self::wrongType($key, 'ein Zeitpunkt nach ISO 8601 (z. B. 2026-10-10T12:00:00+02:00)');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw self::wrongType($key, 'ein gültiger Zeitpunkt nach ISO 8601 (z. B. 2026-10-10T12:00:00+02:00)');
        }
        return $date->getTimestamp();
    }

    private static function wrongType(string $key, string $expected): ApiException
    {
        return ApiException::validation(sprintf('Feld „%s“ muss %s sein.', $key, $expected), ['field' => $key], 'invalid_type');
    }
}
