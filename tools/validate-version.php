<?php

declare(strict_types=1);

/**
 * Validates that FrameworkInfo::VERSION is consistent with:
 *   - docs/openapi/openapi.yaml  info.version
 *   - CHANGELOG.md               most recent released version heading
 *   - README.md                  the "current version" claim (see below)
 *
 * The README check exists because that claim is the one version string a human
 * has to remember to edit, and the repository's own history shows it goes stale:
 * #1548 synced it to v1.10.0, and the v1.11.0 release (#1569) desynced it again
 * within a day, unnoticed for two weeks (#1623). Adding a step to the release
 * checklist would be a second thing to remember, so the claim is machine-checked
 * here instead — this script already runs inside `composer check` and CI.
 *
 * Detection is deliberately narrow: only a `X.Y.Z` literal on a line that also
 * says "current" or "latest" is treated as a claim about the *current* version.
 * Historical references ("forked from `v0.1.1`", "available since v1.6.0") carry
 * no such marker and are left alone.
 *
 * Run via: composer version:check
 *
 * Usage: php tools/validate-version.php [--root=PATH]
 *
 * `--root` exists so the checks can be exercised against fixture trees in tests;
 * it defaults to this repository's root, which is what `composer version:check`
 * relies on. This is NENE2-internal tooling and is not distributed to consumers
 * (ADR 0009 §5), unlike tools/conformance.php or tools/validate-mcp-tools.php.
 */

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

foreach ($argv as $index => $arg) {
    if ($index === 0) {
        continue;
    }

    if (str_starts_with($arg, '--root=')) {
        $explicit = substr($arg, 7);
        $root = realpath($explicit) ?: $explicit;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

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

// --- 4. Read the README's "current version" claim ---
$readmePath = $root . '/README.md';

// A repository without a README simply has no claim to contradict.
if (is_file($readmePath)) {
    $readme = file_get_contents($readmePath);

    if ($readme === false) {
        fwrite(STDERR, "Could not read {$readmePath}\n");
        exit(1);
    }

    foreach (explode("\n", $readme) as $index => $line) {
        // Only lines that present a version as the *present* one are claims.
        if (preg_match('/\b(?:current|latest)\b/i', $line) !== 1) {
            continue;
        }

        if (preg_match_all('/\bv?(\d+\.\d+\.\d+)\b/', $line, $lm) === 0) {
            continue;
        }

        foreach ($lm[1] as $claimed) {
            if ($claimed === $frameworkVersion) {
                continue;
            }

            $errors[] = sprintf(
                "README.md:%d claims current version '%s' but FrameworkInfo::VERSION is '%s'.",
                $index + 1,
                $claimed,
                $frameworkVersion,
            );
        }
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
