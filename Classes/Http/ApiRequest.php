<?php

declare(strict_types=1);

namespace Velundo\Connect\Http;

/**
 * Anfrage an einen Endpunkt: Methode, Abfrageparameter, JSON-Rumpf und die uid aus dem Pfad.
 * Alle Zugriffe prüfen die Eingaben und werfen bei Verstößen eine ApiException (400/422).
 */
final class ApiRequest
{
    public const HIDDEN_VISIBLE = 'visible';
    public const HIDDEN_ALL = 'all';
    public const HIDDEN_HIDDEN = 'hidden';

    public const LIMIT_DEFAULT = 50;
    public const LIMIT_MAX = 200;

    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        private readonly string $rawBody,
        public readonly ?int $uid = null,
        public readonly string $tokenName = '',
    ) {}

    public function has(string $name): bool
    {
        return isset($this->query[$name]) && $this->query[$name] !== '';
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;
        if (!is_scalar($value)) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss ein einfacher Wert sein.', $name), ['parameter' => $name]);
        }
        return trim((string)$value);
    }

    public function int(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if (!$this->has($name)) {
            return $default;
        }
        $raw = $this->query[$name];
        if (!is_scalar($raw) || preg_match('/^-?\d+$/', (string)$raw) !== 1) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss eine ganze Zahl sein.', $name), ['parameter' => $name]);
        }
        $value = (int)$raw;
        if ($value < $min || $value > $max) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss zwischen %d und %d liegen.', $name, $min, $max), ['parameter' => $name]);
        }
        return $value;
    }

    public function limit(): int
    {
        return $this->int('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
    }

    public function offset(): int
    {
        return $this->int('offset', 0, 0);
    }

    public function languageId(): int
    {
        return $this->int('language', 0, 0, 999999);
    }

    /** Sichtbarkeitsfilter: visible (Standard), all, hidden. */
    public function hiddenMode(): string
    {
        $mode = $this->string('hidden', self::HIDDEN_VISIBLE);
        if (!in_array($mode, [self::HIDDEN_VISIBLE, self::HIDDEN_ALL, self::HIDDEN_HIDDEN], true)) {
            throw ApiException::validation('Parameter „hidden“ muss visible, all oder hidden sein.', ['parameter' => 'hidden']);
        }
        return $mode;
    }

    /**
     * JSON-Rumpf als Feld-Liste; leerer Rumpf ergibt ein leeres Feld.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }
        if (trim($this->rawBody) === '') {
            return $this->parsedBody = [];
        }
        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::badRequest('invalid_json', 'Der Anfragerumpf ist kein gültiges JSON.', ['reason' => $e->getMessage()]);
        }
        if (!is_array($decoded)) {
            throw ApiException::badRequest('invalid_json', 'Der Anfragerumpf muss ein JSON-Objekt sein.');
        }
        return $this->parsedBody = $decoded;
    }

    /**
     * Prüft, dass der Rumpf nur Felder der Positivliste enthält und nicht leer ist.
     *
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    public function fields(array $allowed, bool $requireAny = true): array
    {
        $body = $this->body();
        $unknown = array_values(array_diff(array_keys($body), $allowed));
        if ($unknown !== []) {
            throw ApiException::validation(
                sprintf('Unbekannte Felder: %s.', implode(', ', array_map('strval', $unknown))),
                ['unknown' => $unknown, 'allowed' => $allowed],
                'unknown_field'
            );
        }
        if ($requireAny && $body === []) {
            throw ApiException::validation('Der Anfragerumpf enthält keine Felder.', ['allowed' => $allowed], 'empty_body');
        }
        return $body;
    }
}
