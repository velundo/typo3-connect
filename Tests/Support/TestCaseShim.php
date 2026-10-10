<?php

declare(strict_types=1);

/*
 * Kleiner Ersatz für PHPUnit\Framework\TestCase, damit Tests/smoke.php die Prüfungen auch ohne installiertes PHPUnit
 * ausführen kann. Wird nur geladen, wenn die echte Klasse fehlt.
 */

namespace PHPUnit\Framework;

class AssertionFailedError extends \RuntimeException {}

abstract class TestCase
{
    private ?string $expectedException = null;

    protected function setUp(): void {}

    public function runShim(string $method): void
    {
        $this->expectedException = null;
        $this->setUp();
        try {
            $this->{$method}();
        } catch (\Throwable $e) {
            if ($this->expectedException !== null && $e instanceof $this->expectedException) {
                return;
            }
            throw $e;
        }
        if ($this->expectedException !== null) {
            throw new AssertionFailedError('Erwartete Ausnahme ' . $this->expectedException . ' wurde nicht ausgelöst.');
        }
    }

    public function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    public static function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message !== '' ? $message : 'Prüfung fehlgeschlagen.');
    }

    public static function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            self::fail($message !== '' ? $message : 'Erwartet true, erhalten ' . var_export($value, true));
        }
    }

    public static function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            self::fail($message !== '' ? $message : 'Erwartet false, erhalten ' . var_export($value, true));
        }
    }

    public static function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            self::fail($message !== '' ? $message : 'Erwartet null, erhalten ' . var_export($value, true));
        }
    }

    public static function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            self::fail($message !== '' ? $message : 'Erwartet nicht null.');
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            self::fail($message !== '' ? $message : 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            self::fail($message !== '' ? $message : 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    public static function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
    {
        self::assertSame($expected, count($actual), $message);
    }

    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            self::fail($message !== '' ? $message : 'Schlüssel ' . var_export($key, true) . ' fehlt.');
        }
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            self::fail($message !== '' ? $message : 'Zeichenkette enthält nicht ' . var_export($needle, true) . ': ' . $haystack);
        }
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            self::fail($message !== '' ? $message : 'Zeichenkette enthält unerwartet ' . var_export($needle, true) . ': ' . $haystack);
        }
    }

    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        if (preg_match($pattern, $string) !== 1) {
            self::fail($message !== '' ? $message : 'Muster ' . $pattern . ' passt nicht auf ' . var_export($string, true));
        }
    }

    public static function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (!($actual > $expected)) {
            self::fail($message !== '' ? $message : $actual . ' ist nicht größer als ' . $expected);
        }
    }

    public static function assertLessThanOrEqual(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (!($actual <= $expected)) {
            self::fail($message !== '' ? $message : $actual . ' ist nicht kleiner oder gleich ' . $expected);
        }
    }
}
