<?php

declare(strict_types=1);

namespace Nene2\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Checks HTTP conditional-GET headers and returns a 304 Not Modified response when appropriate.
 *
 * Call {@see check()} at the start of any GET handler that supports caching. If it returns a
 * response, send it immediately. If it returns `null`, continue building the full 200 response.
 *
 * ```php
 * $etag = '"' . md5($resource->updatedAt . $resource->id) . '"';
 * $notModified = ConditionalGetHelper::check($request, $responseFactory, $etag, $resource->updatedAt);
 * if ($notModified !== null) {
 *     return $notModified; // 304 with ETag + Last-Modified
 * }
 * return $this->json->create($resource->toArray())
 *     ->withHeader('ETag', $etag)
 *     ->withHeader('Last-Modified', $resource->updatedAt);
 * ```
 *
 * **ETag format**: always pass a strong ETag with surrounding double quotes (e.g. `"abc123"`).
 * Weak ETags (`W/"..."`) are not compared.
 *
 * **Last-Modified format**: ISO 8601 string (e.g. `2026-05-20T12:00:00Z`). If omitted,
 * only `If-None-Match` is evaluated.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class ConditionalGetHelper
{
    /**
     * Returns a 304 Not Modified response when `If-None-Match` or `If-Modified-Since` indicates
     * the client already has a fresh copy. Returns `null` when a full response must be sent.
     *
     * @param string $etag         Strong ETag including surrounding double quotes (e.g. `"abc123"`)
     * @param string $lastModified ISO 8601 timestamp string (e.g. `2026-05-20T12:00:00Z`).
     *                             Leave empty to skip `If-Modified-Since` evaluation.
     */
    public static function check(
        ServerRequestInterface $request,
        ResponseFactoryInterface $responseFactory,
        string $etag,
        string $lastModified = '',
    ): ?ResponseInterface {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return self::notModified($responseFactory, $etag, $lastModified);
        }

        if ($lastModified !== '') {
            $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
            if ($ifModifiedSince !== '' && $ifModifiedSince >= $lastModified) {
                return self::notModified($responseFactory, $etag, $lastModified);
            }
        }

        return null;
    }

    private static function notModified(
        ResponseFactoryInterface $responseFactory,
        string $etag,
        string $lastModified,
    ): ResponseInterface {
        $response = $responseFactory->createResponse(304)->withHeader('ETag', $etag);

        if ($lastModified !== '') {
            $response = $response->withHeader('Last-Modified', $lastModified);
        }

        return $response;
    }
}
