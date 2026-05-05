<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$catalogPath = $root . '/docs/mcp/tools.json';
$openApiPath = $root . '/docs/openapi/openapi.yaml';

$catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
$openApi = Yaml::parseFile($openApiPath);

if (!is_array($catalog)) {
    fwrite(STDERR, "MCP tool catalog must parse to an object.\n");
    exit(1);
}

if (!is_array($openApi)) {
    fwrite(STDERR, "OpenAPI document must parse to an object.\n");
    exit(1);
}

$errors = [];
$allowedSafetyLevels = ['read', 'write', 'admin', 'destructive'];
$tools = $catalog['tools'] ?? null;

if (!is_array($tools) || $tools === []) {
    $errors[] = 'MCP tool catalog must contain at least one tool.';
} else {
    foreach ($tools as $index => $tool) {
        if (!is_array($tool)) {
            $errors[] = sprintf('Tool at index %d must be an object.', $index);
            continue;
        }

        validateTool($tool, $index, $openApi, $allowedSafetyLevels, $errors);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, sprintf("MCP tool catalog validation error: %s\n", $error));
    }

    exit(1);
}

echo "MCP tool catalog is valid.\n";

/**
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $openApi
 * @param list<string> $allowedSafetyLevels
 * @param list<string> $errors
 */
function validateTool(array $tool, int $index, array $openApi, array $allowedSafetyLevels, array &$errors): void
{
    $name = $tool['name'] ?? null;
    $safety = $tool['safety'] ?? null;
    $source = $tool['source'] ?? null;
    $responseSchemaRef = $tool['responseSchemaRef'] ?? null;

    if (!is_string($name) || $name === '') {
        $errors[] = sprintf('Tool at index %d must have a non-empty name.', $index);
    }

    if (!is_string($safety) || !in_array($safety, $allowedSafetyLevels, true)) {
        $errors[] = sprintf('Tool "%s" has an unsupported safety level.', displayName($name, $index));
    }

    if (!is_array($source)) {
        $errors[] = sprintf('Tool "%s" must define a source object.', displayName($name, $index));
        return;
    }

    $operation = openApiOperation($openApi, $source);

    if ($operation === null) {
        $errors[] = sprintf('Tool "%s" source does not match an OpenAPI operation.', displayName($name, $index));
        return;
    }

    $operationId = $source['operationId'] ?? null;

    if (($operation['operationId'] ?? null) !== $operationId) {
        $errors[] = sprintf('Tool "%s" operationId does not match OpenAPI.', displayName($name, $index));
    }

    if ($responseSchemaRef !== successResponseSchemaRef($operation)) {
        $errors[] = sprintf('Tool "%s" responseSchemaRef does not match the OpenAPI 200 response schema.', displayName($name, $index));
    }
}

/**
 * @param array<string, mixed> $openApi
 * @param array<string, mixed> $source
 * @return array<string, mixed>|null
 */
function openApiOperation(array $openApi, array $source): ?array
{
    $method = $source['method'] ?? null;
    $path = $source['path'] ?? null;

    if (!is_string($method) || !is_string($path)) {
        return null;
    }

    $operation = $openApi['paths'][$path][strtolower($method)] ?? null;

    return is_array($operation) ? $operation : null;
}

/**
 * @param array<string, mixed> $operation
 */
function successResponseSchemaRef(array $operation): ?string
{
    $schemaRef = $operation['responses']['200']['content']['application/json']['schema']['$ref'] ?? null;

    return is_string($schemaRef) ? $schemaRef : null;
}

function displayName(mixed $name, int $index): string
{
    return is_string($name) && $name !== '' ? $name : sprintf('#%d', $index);
}
