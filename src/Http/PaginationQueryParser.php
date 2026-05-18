<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses and validates ?limit= and ?offset= query parameters into a PaginationQuery.
 *
 * Throws ValidationException (→ 422) when either parameter is out of range.
 * Non-numeric values are coerced to 0 (casting behaviour of PHP intval).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class PaginationQueryParser
{
    /**
     * @throws ValidationException
     */
    public static function parse(
        ServerRequestInterface $request,
        int $defaultLimit = 20,
        int $maxLimit = 100,
    ): PaginationQuery {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int) $query['limit'] : $defaultLimit;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;

        $errors = [];

        if ($limit < 1 || $limit > $maxLimit) {
            $errors[] = new ValidationError(
                'limit',
                'limit must be between 1 and ' . $maxLimit . '.',
                'out_of_range',
            );
        }

        if ($offset < 0) {
            $errors[] = new ValidationError('offset', 'offset must be 0 or greater.', 'out_of_range');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new PaginationQuery(limit: $limit, offset: $offset);
    }
}
