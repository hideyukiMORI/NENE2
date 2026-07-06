<?php

declare(strict_types=1);

/**
 * Conformance linter — enforces the low-false-positive `error`-tier fleet rules
 * (design doc `_work/reports/2026-07-06/upstream-design/04-conformance-linter.md`,
 * layer error: D1 JWT default secret / D2 dependency feature-branch pin /
 * D3 `composer check` self-registration / D4 raw current-time reads).
 *
 * Distributed the same way as the other framework validators: consumers add
 *   "conformance": "php vendor/hideyukimori/nene2/tools/conformance.php --root=."
 * to composer.json and "@conformance" to scripts.check.
 *
 * Usage:
 *   php tools/conformance.php [--root=PATH] [--format=text|json] [--write-baseline]
 *
 * Exit codes: 0 = no active errors, 1 = active error findings, 2 = usage/config error.
 */

use Nene2\Conformance\Baseline;
use Nene2\Conformance\ConformanceRunner;
use Nene2\Conformance\Finding;
use Nene2\Conformance\RunResult;

// When run via `composer conformance` or directly, getcwd() is the project root.
// --root=<path> overrides this for explicit invocation from another directory.
$cwd = getcwd();
$projectRoot = $cwd !== false ? $cwd : dirname(__DIR__);
$format = 'text';
$writeBaseline = false;

foreach ($argv as $index => $arg) {
    if ($index === 0) {
        continue;
    }

    if (str_starts_with($arg, '--root=')) {
        $explicit = substr($arg, 7);
        $projectRoot = realpath($explicit) ?: $explicit;
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif ($arg === '--write-baseline') {
        $writeBaseline = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

if (!in_array($format, ['text', 'json'], true)) {
    fwrite(STDERR, "Unknown --format '{$format}' (expected 'text' or 'json').\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$root = rtrim(str_replace('\\', '/', $projectRoot), '/');
$baselinePath = $root . '/' . ConformanceRunner::BASELINE_FILENAME;
$runner = ConformanceRunner::withDefaultRules();
$baseline = Baseline::load($baselinePath);

if ($baseline->validationErrors() !== []) {
    foreach ($baseline->validationErrors() as $error) {
        fwrite(STDERR, "Baseline error: {$error}\n");
    }

    exit(2);
}

if ($writeBaseline) {
    $data = $runner->buildBaseline($root, $baseline);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false || file_put_contents($baselinePath, $json . "\n") === false) {
        fwrite(STDERR, "Could not write baseline to {$baselinePath}\n");
        exit(2);
    }

    fwrite(STDERR, sprintf("Wrote baseline with %d ignore entrie(s) to %s\n", count($data['ignore']), $baselinePath));
    exit(0);
}

$result = $runner->run($root, $baseline);

if ($format === 'json') {
    echo renderJson($result), "\n";
} else {
    fwrite(STDERR, renderText($result));
}

exit($result->exitCode());

function renderText(RunResult $result): string
{
    $errors = $result->errors();

    if ($errors === []) {
        return sprintf(
            "Conformance OK — no error findings (%d suppressed by baseline/ignore).\n",
            $result->suppressed,
        );
    }

    $lines = [sprintf(
        "Conformance: %d error finding(s), %d suppressed by baseline/ignore.\n",
        count($errors),
        $result->suppressed,
    )];

    foreach ($errors as $finding) {
        $location = $finding->line > 0 ? "{$finding->file}:{$finding->line}" : $finding->file;
        $lines[] = sprintf("  [%s] %s\n        %s\n", $finding->ruleId, $location, $finding->message);
    }

    $lines[] = "\nBaseline existing drift with: php tools/conformance.php --write-baseline\n";
    $lines[] = "Allowlist a false positive in conformance.baseline.json (\"allow\": with a required \"reason\").\n";

    return implode('', $lines);
}

function renderJson(RunResult $result): string
{
    $payload = [
        'ok' => $result->exitCode() === 0,
        'suppressed' => $result->suppressed,
        'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $result->findings),
    ];

    return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
