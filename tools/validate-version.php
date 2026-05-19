<?php

declare(strict_types=1);

/**
 * Validates that FrameworkInfo::VERSION is consistent with:
 *   - docs/openapi/openapi.yaml  info.version
 *   - CHANGELOG.md               most recent released version heading
 *
 * Run via: composer version:check
 */

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$errors = [];

// --- 1. Read FrameworkInfo::VERSION ---
$frameworkInfoPath = $root . '/src/FrameworkInfo.php';
$frameworkInfoSrc = file_get_contents($frameworkInfoPath);

if ($frameworkInfoSrc === false) {
    fwrite(STDERR, "Could not read {$frameworkInfoPath}\n");
    exit(1);
}

if (!preg_match("/VERSION\s*=\s*'([^']+)'/", $frameworkInfoSrc, $m)) {
    fwrite(STDERR, "Could not extract VERSION from FrameworkInfo.php\n");
    exit(1);
}

$frameworkVersion = $m[1];

// --- 2. Read OpenAPI info.version ---
$openapiPath = $root . '/docs/openapi/openapi.yaml';
$openapiDoc = Yaml::parseFile($openapiPath);
$openapiVersion = is_array($openapiDoc) ? ($openapiDoc['info']['version'] ?? null) : null;

if (!is_string($openapiVersion)) {
    $errors[] = 'docs/openapi/openapi.yaml: info.version is missing or not a string.';
} elseif ($openapiVersion !== $frameworkVersion) {
    $errors[] = sprintf(
        "docs/openapi/openapi.yaml info.version is '%s' but FrameworkInfo::VERSION is '%s'.",
        $openapiVersion,
        $frameworkVersion,
    );
}

// --- 3. Read latest released version from CHANGELOG.md ---
$changelogPath = $root . '/CHANGELOG.md';
$changelog = file_get_contents($changelogPath);

if ($changelog === false) {
    fwrite(STDERR, "Could not read {$changelogPath}\n");
    exit(1);
}

// Match the first non-Unreleased version heading: ## [1.2.3]
if (!preg_match('/^##\s+\[(\d+\.\d+\.\d+)\]/m', $changelog, $cm)) {
    $errors[] = "CHANGELOG.md: no released version heading found (expected '## [X.Y.Z]').";
} else {
    $changelogVersion = $cm[1];

    if ($changelogVersion !== $frameworkVersion) {
        $errors[] = sprintf(
            "CHANGELOG.md latest released version is '%s' but FrameworkInfo::VERSION is '%s'.",
            $changelogVersion,
            $frameworkVersion,
        );
    }
}

// --- Report ---
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "Version consistency error: {$error}\n");
    }

    exit(1);
}

echo "Version consistency OK: {$frameworkVersion}\n";
