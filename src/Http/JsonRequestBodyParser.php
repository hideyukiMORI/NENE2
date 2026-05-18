<?php

declare(strict_types=1);

namespace Nene2\Http;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses the request body as a JSON object.
 *
 * Throws JsonBodyParseException (→ 400) for any of:
 * - empty body
 * - syntactically invalid JSON
 * - valid JSON that is not an object (e.g. a string, number, or array)
 *
 * This keeps 400 (malformed input) distinct from 422 (structurally valid
 * input that fails business validation).
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class JsonRequestBodyParser
{
    /**
     * @return array<string, mixed>
     * @throws JsonBodyParseException
     */
    public static function parse(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        if ($raw === '') {
            throw new JsonBodyParseException('Request body is empty. A JSON object is required.');
        }

        try {
            // Decode without associative: true so JSON objects become stdClass and
            // JSON arrays remain array — allowing us to tell them apart.
            $decoded = json_decode($raw, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonBodyParseException(
                'Request body contains invalid JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!$decoded instanceof \stdClass) {
            throw new JsonBodyParseException(
                'Request body must be a JSON object, got ' . get_debug_type($decoded) . '.',
            );
        }

        // Re-decode as associative to return array<string, mixed> for handler use.
        /** @var array<string, mixed> */
        return (array) json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
