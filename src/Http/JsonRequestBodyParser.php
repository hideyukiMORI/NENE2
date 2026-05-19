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
            $hint = is_array($decoded)
                ? ' Hint: in PHP, json_encode([]) produces "[]" (a JSON array). Use json_encode((object)[]) or new stdClass() to produce "{}" (a JSON object).'
                : '';
            throw new JsonBodyParseException(
                'Request body must be a JSON object, got ' . get_debug_type($decoded) . '.' . $hint,
            );
        }

        /** @var array<string, mixed> */
        return self::toArray($decoded);
    }

    /**
     * Recursively converts a stdClass tree (from json_decode associative:false)
     * to a nested array<string, mixed> without a second json_decode call.
     */
    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return array_map(self::toArray(...), (array) $value);
        }

        if (is_array($value)) {
            return array_map(self::toArray(...), $value);
        }

        return $value;
    }
}
