<?php

/**
 * Generates docs/reference/http-endpoints.md from docs/openapi/openapi.yaml.
 *
 * Usage: php tools/openapi-to-md.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$openapi = Yaml::parseFile($root . '/docs/openapi/openapi.yaml');

/** @var array<string, mixed> $paths */
$paths = $openapi['paths'] ?? [];

/** @var array<string, mixed> $components */
$components = $openapi['components'] ?? [];

/** @var array<string, mixed> $responseDefs */
$responseDefs = $components['responses'] ?? [];

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

/**
 * Resolve a $ref like '#/components/responses/NotFound' to its definition.
 *
 * @param  array<string, mixed>|string $value
 * @param  array<string, mixed>        $doc
 * @return array<string, mixed>|null
 */
function resolveRef(mixed $value, array $doc): ?array
{
    if (!is_array($value) || !isset($value['$ref'])) {
        return null;
    }
    $ref = $value['$ref'];
    if (!str_starts_with($ref, '#/')) {
        return null;
    }
    $parts = explode('/', ltrim($ref, '#/'));
    $node = $doc;
    foreach ($parts as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return is_array($node) ? $node : null;
}

/**
 * Extract HTTP status codes from an operation's responses map.
 *
 * @param  array<string, mixed> $responses
 * @param  array<string, mixed> $doc
 * @return int[]
 */
function statusCodes(array $responses, array $doc): array
{
    $codes = [];
    foreach ($responses as $status => $response) {
        $codes[] = (int) $status;
    }
    sort($codes);
    return $codes;
}

/**
 * Determine the auth label for an operation.
 *
 * @param  array<string, mixed> $operation
 * @return string
 */
function authLabel(array $operation): string
{
    $security = $operation['security'] ?? null;
    if ($security === null || $security === []) {
        return 'None';
    }
    $schemes = [];
    foreach ($security as $requirement) {
        foreach (array_keys($requirement) as $scheme) {
            $schemes[] = match ($scheme) {
                'ApiKeyAuth' => '`X-NENE2-API-Key`',
                'bearerAuth' => '`Bearer` token',
                default      => '`' . $scheme . '`',
            };
        }
    }
    return implode(' or ', $schemes);
}

/**
 * Split status codes into success (< 400) and error (>= 400, excluding 500).
 *
 * @param  int[] $codes
 * @return array{success: string, errors: string}
 */
function splitCodes(array $codes): array
{
    $success = [];
    $errors  = [];
    foreach ($codes as $code) {
        if ($code < 400) {
            $success[] = '`' . $code . '`';
        } elseif ($code !== 500) {
            $errors[] = '`' . $code . '`';
        }
    }
    return [
        'success' => implode(', ', $success) ?: '—',
        'errors'  => implode(', ', $errors) ?: '—',
    ];
}

// -------------------------------------------------------------------------
// Collect operations grouped by path prefix
// -------------------------------------------------------------------------

/** @var array<string, list<array{method:string,path:string,operation:array<string,mixed>}>> */
$groups = [
    'health'    => [],
    'notes'     => [],
    'tags'      => [],
    'protected' => [],
];

$methodOrder = ['get', 'post', 'put', 'delete', 'patch'];

foreach ($paths as $path => $pathItem) {
    foreach ($methodOrder as $method) {
        if (!isset($pathItem[$method])) {
            continue;
        }
        $op = $pathItem[$method];
        $tags = $op['tags'] ?? [];

        if (in_array($path, ['/', '/health', '/examples/ping'], true)) {
            $groups['health'][] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $op];
        } elseif (str_starts_with($path, '/examples/notes')) {
            $groups['notes'][] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $op];
        } elseif (str_starts_with($path, '/examples/tags')) {
            $groups['tags'][] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $op];
        } elseif ($path === '/examples/protected') {
            $groups['protected'][] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $op];
        }
    }
}

// -------------------------------------------------------------------------
// Build health table (no success/error split — simpler format)
// -------------------------------------------------------------------------

/**
 * @param  list<array{method:string,path:string,operation:array<string,mixed>}> $entries
 * @param  array<string, mixed> $doc
 */
function buildHealthTable(array $entries, array $doc): string
{
    $rows = [];
    foreach ($entries as $e) {
        $codes = statusCodes($e['operation']['responses'] ?? [], $doc);
        $auth  = authLabel($e['operation']);
        $split = splitCodes($codes);

        // Describe response inline for health/ping/root
        $desc = match ($e['path']) {
            '/health'        => '`200` `{ service, status[, checks] }` · `503` when any check fails',
            '/examples/ping' => '`200` `{ message }`',
            '/'              => '`200` `{ name, description, status }` JSON smoke response',
            default          => $split['success'],
        };

        $rows[] = '| `' . $e['method'] . '` | `' . $e['path'] . '` | ' . $auth . ' | ' . $desc . ' |';
    }
    return "| Method | Path | Auth | Response |\n|---|---|---|---|\n" . implode("\n", $rows);
}

// -------------------------------------------------------------------------
// Build standard endpoint table (with success/errors columns)
// -------------------------------------------------------------------------

/**
 * @param  list<array{method:string,path:string,operation:array<string,mixed>}> $entries
 * @param  array<string, mixed> $doc
 */
function buildEndpointTable(array $entries, array $doc): string
{
    $rows = [];
    foreach ($entries as $e) {
        $codes = statusCodes($e['operation']['responses'] ?? [], $doc);
        $auth  = authLabel($e['operation']);
        $split = splitCodes($codes);
        $rows[] = '| `' . $e['method'] . '` | `' . $e['path'] . '` | ' . $auth . ' | ' . $split['success'] . ' | ' . $split['errors'] . ' |';
    }
    return "| Method | Path | Auth | Success | Errors |\n|---|---|---|---|---|\n" . implode("\n", $rows);
}

// -------------------------------------------------------------------------
// Render Markdown
// -------------------------------------------------------------------------

$md  = "# HTTP Endpoints\n\n";
$md .= "All endpoints exposed by the NENE2 example application.\n";
$md .= "Every JSON response follows the schemas in `docs/openapi/openapi.yaml`.\n\n";

// Sort health entries: /health first, then /examples/ping, then /
$healthOrder = ['/health' => 0, '/examples/ping' => 1, '/' => 2];
usort($groups['health'], static fn ($a, $b) => ($healthOrder[$a['path']] ?? 99) <=> ($healthOrder[$b['path']] ?? 99));

$md .= "## Health and diagnostics\n\n";
$md .= buildHealthTable($groups['health'], $openapi) . "\n\n";

$md .= "## Notes\n\n";
$md .= buildEndpointTable($groups['notes'], $openapi) . "\n\n";

$md .= "## Tags\n\n";
$md .= buildEndpointTable($groups['tags'], $openapi) . "\n\n";

$md .= "## Protected (machine client)\n\n";
$md .= buildEndpointTable($groups['protected'], $openapi) . "\n\n";
$md .= "Requests to the protected endpoint must include a valid `Authorization: Bearer <token>` header with a JWT signed with `NENE2_LOCAL_JWT_SECRET`.\n\n";

$md .= "## Response shapes\n\n";
$md .= "**Collection envelope** (shared by Notes and Tags):\n\n";
$md .= "```json\n{ \"items\": [...], \"limit\": 20, \"offset\": 0 }\n```\n\n";
$md .= "**Note object**:\n\n";
$md .= "```json\n{ \"id\": 1, \"title\": \"My note\", \"body\": \"Content here\" }\n```\n\n";
$md .= "**Tag object**:\n\n";
$md .= "```json\n{ \"id\": 1, \"name\": \"backend\" }\n```\n\n";
$md .= "Error responses follow [RFC 9457 Problem Details](./problem-details-types).\n";

// -------------------------------------------------------------------------
// Write output
// -------------------------------------------------------------------------

$outPath = $root . '/docs/reference/http-endpoints.md';
file_put_contents($outPath, $md);
echo "Written: docs/reference/http-endpoints.md\n";
