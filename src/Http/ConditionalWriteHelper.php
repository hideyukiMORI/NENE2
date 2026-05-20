<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Evaluates the `If-Match` precondition for conditional writes (PUT, PATCH, DELETE).
 *
 * Call {@see check()} at the start of any write handler that supports optimistic locking.
 * If it returns a response, send it immediately. If it returns `null`, the precondition passed
 * and the write may proceed.
 *
 * ```php
 * $etag = '"v' . $document->version . '"';
 * $block = ConditionalWriteHelper::check($request, $problems, $etag);
 * if ($block !== null) {
 *     return $block; // 412 Precondition Failed or 428 Precondition Required
 * }
 * // safe to write — ETag matched
 * ```
 *
 * **ETag format**: always pass a strong ETag with surrounding double quotes (e.g. `"v3"` or `"abc123"`).
 * Weak ETags (`W/"..."`) are not compared.
 *
 * **`If-Match: *` wildcard**: passes unconditionally when the resource exists.
 * The caller is responsible for returning 404 when the resource is absent.
 *
 * **`$require = false`**: when set, a missing `If-Match` header is allowed (no 428 returned).
 * Useful when optimistic locking is optional rather than enforced.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class ConditionalWriteHelper
{
    /**
     * Returns a 412 or 428 problem-details response when the `If-Match` precondition fails.
     * Returns `null` when the precondition passes (write is safe to proceed).
     *
     * @param string $currentEtag Strong ETag of the current resource, with surrounding double quotes (e.g. `"v3"`)
     * @param bool   $require     When `true` (default), an absent `If-Match` header returns 428.
     *                            When `false`, an absent header is treated as a pass.
     */
    public static function check(
        ServerRequestInterface $request,
        ProblemDetailsResponseFactory $problems,
        string $currentEtag,
        bool $require = true,
    ): ?ResponseInterface {
        $ifMatch = $request->getHeaderLine('If-Match');

        if ($ifMatch === '') {
            if (!$require) {
                return null;
            }

            return $problems->create(
                $request,
                'precondition-required',
                'Precondition Required',
                428,
                'If-Match header is required. Fetch the current ETag and retry.',
            );
        }

        // Wildcard: any existing version is acceptable; caller checks for 404.
        if ($ifMatch === '*') {
            return null;
        }

        if ($ifMatch === $currentEtag) {
            return null;
        }

        return $problems->create(
            $request,
            'precondition-failed',
            'Precondition Failed',
            412,
            'The supplied ETag does not match the current resource version. Fetch the latest ETag and retry.',
        );
    }
}
