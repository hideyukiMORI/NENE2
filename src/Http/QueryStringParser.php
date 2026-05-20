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
 *   $tags     = QueryStringParser::commaSeparated($request, 'tags'); // list<string>|null
 *   $tags     = QueryStringParser::array($request, 'tags');         // list<string>|null — ?tags[]=php&tags[]=api
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

    /**
     * Splits a comma-separated query parameter into a list of non-empty trimmed strings.
     *
     * Returns null when the key is absent or the value is an empty string.
     * Each item is trimmed; empty items after trimming are removed.
     *
     * Common use case: `?tags=php,lang` → `['php', 'lang']`
     *
     * @return list<string>|null
     */
    public static function commaSeparated(ServerRequestInterface $request, string $key): ?array
    {
        $raw = self::string($request, $key);

        if ($raw === null) {
            return null;
        }

        /** @var list<string> $items */
        $items = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $items !== [] ? $items : null;
    }

    /**
     * Returns a list of string values for a PHP-style repeated query parameter.
     *
     * Handles `?tags[]=php&tags[]=api` → `['php', 'api']`.
     * PSR-7 implementations parse this into `['tags' => ['php', 'api']]` in getQueryParams().
     *
     * Returns null when the key is absent.
     * Each item is trimmed; empty items after trimming are removed.
     *
     * Note: if the key exists as a plain string (not an array), returns null.
     * Use commaSeparated() for `?tags=php,api` format instead.
     *
     * @return list<string>|null
     */
    public static function array(ServerRequestInterface $request, string $key): ?array
    {
        $params = $request->getQueryParams();

        if (!array_key_exists($key, $params)) {
            return null;
        }

        $raw = $params[$key];

        if (!is_array($raw)) {
            return null;
        }

        /** @var list<string> $items */
        $items = array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            $raw,
        ), static fn (string $s): bool => $s !== ''));

        return $items !== [] ? $items : null;
    }
}
