<?php

declare(strict_types=1);

namespace Velundo\Connect\Http;

/**
 * Ergebnis eines Endpunkts: Nutzdaten und HTTP-Status.
 */
final class Result
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $data,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self($data, 200);
    }

    /** @param array<string, mixed> $data */
    public static function created(array $data): self
    {
        return new self($data, 201);
    }

    /**
     * Seitenweise Liste nach Vertrag: {"items":[…],"total":n,"limit":l,"offset":o}
     *
     * @param list<array<string, mixed>> $items
     */
    public static function page(array $items, int $total, int $limit, int $offset): self
    {
        return new self(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset], 200);
    }
}
