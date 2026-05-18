<?php

declare(strict_types=1);

namespace Nene2\Http;

/**
 * Parsed and validated pagination parameters from a query string.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @see PaginationQueryParser
 */
final readonly class PaginationQuery
{
    public function __construct(
        public int $limit,
        public int $offset,
    ) {
    }
}
