<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Standardised list-endpoint response envelope for paginated collections.
 *
 * Wraps the serialised items array with pagination metadata. The `$total` field is optional:
 * omit it when the repository does not perform a COUNT query; include it when the total record
 * count is available so that clients can determine the last page without an extra request.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @see PaginationQuery
 * @see PaginationQueryParser
 */
final readonly class PaginationResponse
{
    /**
     * @param list<mixed> $items Serialised resource items (already converted to arrays).
     * @param int $limit         The effective limit that was applied.
     * @param int $offset        The effective offset that was applied.
     * @param int|null $total    Total number of matching records, or null when not counted.
     */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
        public ?int $total = null,
    ) {
    }

    /**
     * Returns the response payload ready to pass to {@see JsonResponseFactory::create()}.
     *
     * The `total` key is present only when a non-null value was provided.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'items'  => $this->items,
            'limit'  => $this->limit,
            'offset' => $this->offset,
        ];

        if ($this->total !== null) {
            $data['total'] = $this->total;
        }

        return $data;
    }
}
