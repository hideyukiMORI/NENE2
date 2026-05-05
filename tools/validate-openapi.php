<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = dirname(__DIR__) . '/docs/openapi/openapi.yaml';

try {
    $document = Yaml::parseFile($path);
} catch (ParseException $exception) {
    fwrite(STDERR, sprintf("OpenAPI YAML parse error: %s\n", $exception->getMessage()));
    exit(1);
}

if (!is_array($document)) {
    fwrite(STDERR, "OpenAPI document must parse to a mapping.\n");
    exit(1);
}

$errors = [];

foreach (['openapi', 'info', 'paths', 'components'] as $requiredKey) {
    if (!array_key_exists($requiredKey, $document)) {
        $errors[] = sprintf('Missing required top-level key: %s', $requiredKey);
    }
}

if (($document['openapi'] ?? null) !== '3.1.0') {
    $errors[] = 'OpenAPI version must be 3.1.0.';
}

validateInternalReferences($document, $document, '#', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, sprintf("OpenAPI validation error: %s\n", $error));
    }

    exit(1);
}

echo "OpenAPI contract is valid.\n";

/**
 * @param array<mixed> $node
 * @param array<mixed> $document
 * @param list<string> $errors
 */
function validateInternalReferences(array $node, array $document, string $path, array &$errors): void
{
    foreach ($node as $key => $value) {
        $childPath = $path . '/' . (string) $key;

        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
            if (!hasJsonPointer($document, $value)) {
                $errors[] = sprintf('Unresolved internal reference at %s: %s', $path, $value);
            }

            continue;
        }

        if (is_array($value)) {
            validateInternalReferences($value, $document, $childPath, $errors);
        }
    }
}

/**
 * @param array<mixed> $document
 */
function hasJsonPointer(array $document, string $reference): bool
{
    $current = $document;

    foreach (explode('/', substr($reference, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return false;
        }

        $current = $current[$segment];
    }

    return true;
}
