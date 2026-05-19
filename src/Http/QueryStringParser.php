<?php

declare(strict_types=1);

namespace Nene2\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed helpers for extracting individual query-string parameters from a PSR-7 request.
 *
 * PaginationQueryParser handles limit/offset. This class handles everything else —
 * plain string, integer, and boolean query parameters — with safe defaults for
 * absent or empty-string values.
 *
 * Usage:
 *   $category = QueryStringParser::string($request, 'category');     // ?string
 *   $page     = QueryStringParser::int($request, 'page');            // ?int
 *   $isRead   = QueryStringParser::bool($request, 'is_read');        // ?bool (null when absent)
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class QueryStringParser
{
    /**
     * Returns the raw string value for a query parameter, or null when the key is
     * absent or the value is an empty string.
     */
    public static function string(ServerRequestInterface $request, string $key): ?string
    {
        $params = $request->getQueryParams();

        if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') {
            return null;
        }

        return $params[$key];
    }

    /**
     * Returns the integer value for a query parameter, or null when the key is
     * absent, empty, or not a valid integer string.
     */
    public static function int(ServerRequestInterface $request, string $key): ?int
    {
        $raw = self::string($request, $key);

        if ($raw === null) {
            return null;
        }

        if (!ctype_digit(ltrim($raw, '-')) || $raw === '-') {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Returns a boolean for a query parameter, or null when the key is absent.
     *
     * Falsy strings: "0", "false", "no" — everything else is truthy.
     * This mirrors the most common HTTP convention where "?flag=false" means false.
     *
     * Note: an empty-string value ("?flag=") is treated as absent (returns null).
     */
    public static function bool(ServerRequestInterface $request, string $key): ?bool
    {
        $params = $request->getQueryParams();

        if (!array_key_exists($key, $params)) {
            return null;
        }

        $raw = $params[$key];

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return !in_array($raw, ['0', 'false', 'no'], true);
    }
}
