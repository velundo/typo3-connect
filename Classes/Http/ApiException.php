<?php

declare(strict_types=1);

namespace Velundo\Connect\Http;

/**
 * Fehler der Schnittstelle: wird von der Middleware in {"error":{"code","message","details"}} übersetzt.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(string $code, string $message, array $details = []): self
    {
        return new self(400, $code, $message, $details);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', 'Kein gültiges Zugangstoken.');
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    /** @param array<string, mixed> $details */
    public static function notFound(string $message = 'Unbekannter Endpunkt.', string $code = 'not_found', array $details = []): self
    {
        return new self(404, $code, $message, $details);
    }

    public static function featureUnavailable(string $feature): self
    {
        return new self(404, 'feature_unavailable', sprintf('Die Funktion „%s“ ist in dieser Installation nicht verfügbar.', $feature), ['feature' => $feature]);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'conflict', $message, $details);
    }

    /** @param array<string, mixed> $details */
    public static function validation(string $message, array $details = [], string $code = 'validation_failed'): self
    {
        return new self(422, $code, $message, $details);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Zu viele Anfragen – bitte kurz warten.', ['retry_after' => $retryAfter]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details === [] ? new \stdClass() : $this->details,
            ],
        ];
    }
}
