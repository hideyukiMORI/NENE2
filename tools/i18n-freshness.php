<?php

declare(strict_types=1);

/**
 * Translation freshness checker — reports translations that are missing or that
 * were written against an older version of their English original (#1627).
 *
 * `composer howto:index` already makes a *missing* translation a red build for
 * `docs/howto/`, because the regenerated locale index loses a row. Nothing
 * watched the other failure mode: a translation that exists, so every index is
 * complete, while the English original moved on underneath it. That is the gap
 * this script closes.
 *
 * ## Why hashes and not commit dates
 *
 * Comparing "English file's last commit" against "translation's last commit"
 * looks obvious and is useless here: measured on this repository it flagged
 * 248 of 273 `ja` pairs, and 245 of those were caused by two bulk commits that
 * added YAML frontmatter to every guide (#1328/#1330) without touching a single
 * byte of prose. Any signal that a mass edit can saturate is not a signal.
 *
 * So the unit of comparison is a hash of the English *body* with the frontmatter
 * stripped — which makes those 245 disappear by construction, and keeps this
 * check from overlapping `composer howto:frontmatter` (that one owns the
 * frontmatter; this one deliberately never looks at it).
 *
 * ## Why two hashes
 *
 * A single hash cannot tell a typo from a renamed environment variable, so
 * every entry carries two:
 *
 *   - `content`   — the whole normalized body. Any edit changes it.
 *   - `structure` — only the load-bearing parts: headings, fenced code blocks,
 *                   table rows, and link targets. Prose sentences are excluded.
 *
 * A `structure` change means the translation now says something false (a command,
 * a value, a table cell, a link moved), so it is an error. A `content`-only
 * change is prose polish, so it is a warning: making it an error would let one
 * typo fix in English block every unrelated PR until five translations catch up.
 *
 * ## Scope
 *
 * `docs/development/` and `docs/integrations/` are English-only by policy
 * (`docs/development/language-policy.md`). They are not gaps, and walking every
 * English file blindly would report 43 phantom omissions per locale. The scope,
 * the locale list and the exclusions therefore live in the baseline file as
 * data, so changing them is a reviewable diff rather than a code edit.
 *
 * Usage:
 *   php tools/i18n-freshness.php [--root=PATH] [--format=text|json]
 *                                [--write-baseline] [--bootstrap]
 *
 * `--bootstrap` seeds the baseline from git history — for each translation it
 * records the English hashes as they were at that translation's last commit, so
 * the first ordinary run reports the drift that already exists instead of
 * blessing it. It refuses to run without a working git (the standard `app`
 * container has no git binary, and a silent fallback to current hashes would
 * quietly bless every stale translation — the exact outcome this check exists to
 * prevent). `--write-baseline` records the *current* English hashes, which is
 * what you run after updating a translation.
 *
 * This is NENE2-internal tooling and is not distributed to consumer projects
 * (ADR 0009 §5), unlike tools/conformance.php or tools/validate-mcp-tools.php:
 * the six-locale layout and the English-only policy are NENE2's own premises.
 *
 * Exit codes: 0 = no errors, 1 = error findings, 2 = usage/config error.
 */

const BASELINE_FILENAME = 'translations.baseline.json';
const BASELINE_VERSION = 1;

const DEFAULT_LOCALES = ['ja', 'de', 'fr', 'zh', 'pt-br'];

/** Directories under docs/ that are mirrored into every locale. */
const DEFAULT_SCOPE = ['howto', 'tutorial', 'reference', 'explanation'];

/**
 * Paths inside the scope that are not hand-written translatable prose.
 *
 * `howto/README.md` and `howto/by-tag.md` are build artifacts of
 * `composer howto:index`. `explanation/index.md` has no locale counterpart and
 * the locale navigation links straight to `explanation/why-psr` rather than to a
 * locale landing page, so it reads as intentionally English-only — revisit if a
 * locale landing page is ever wired into the nav.
 *
 * `docs/index.md` is absent from the scope for a different reason: it is a
 * VitePress `layout: home` page whose entire translatable content lives in
 * frontmatter, which this checker deliberately does not hash.
 */
const DEFAULT_EXCLUDE = ['howto/README.md', 'howto/by-tag.md', 'explanation/index.md'];

const SEVERITY_ERROR = 'error';
const SEVERITY_WARN = 'warn';

// --- Arguments ---------------------------------------------------------------

$root = dirname(__DIR__);
$format = 'text';
$writeBaseline = false;
$bootstrap = false;

foreach ($argv as $index => $arg) {
    if ($index === 0) {
        continue;
    }

    if (str_starts_with($arg, '--root=')) {
        $explicit = substr($arg, 7);
        $root = realpath($explicit) ?: $explicit;
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif ($arg === '--write-baseline') {
        $writeBaseline = true;
    } elseif ($arg === '--bootstrap') {
        $bootstrap = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

if (!in_array($format, ['text', 'json'], true)) {
    fwrite(STDERR, "Unknown format: {$format} (expected text or json)\n");
    exit(2);
}

if ($writeBaseline && $bootstrap) {
    fwrite(STDERR, "--write-baseline and --bootstrap are mutually exclusive.\n");
    exit(2);
}

// --- Normalization and hashing ----------------------------------------------

/**
 * Strips the YAML frontmatter block and normalizes whitespace so that
 * presentation-only churn (line endings, trailing spaces, leading/trailing blank
 * lines) does not read as a content change.
 *
 * Runs of blank lines are deliberately preserved: inside a fenced code block
 * they are content, and collapsing them would hide real edits.
 */
function normalizeBody(string $raw): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $raw);

    if (str_starts_with($text, "---\n") && preg_match('/^---\n(?:.*?)\n---\n/s', $text, $m) === 1) {
        $text = substr($text, strlen($m[0]));
    }

    $lines = array_map(static fn (string $line): string => rtrim($line, " \t"), explode("\n", $text));

    return trim(implode("\n", $lines), "\n");
}

/**
 * Extracts the parts of a body that a translation cannot get wrong without
 * saying something false: headings, fenced code blocks (fence line included, so
 * the language tag counts), table rows, and link targets.
 *
 * Prose is intentionally dropped — that is the whole point of the second hash.
 */
function structureText(string $body): string
{
    $parts = [];
    $inFence = false;
    $fenceMarker = '';

    foreach (explode("\n", $body) as $line) {
        $trimmed = ltrim($line);

        if (preg_match('/^(`{3,}|~{3,})/', $trimmed, $fm) === 1) {
            if (!$inFence) {
                $inFence = true;
                $fenceMarker = $fm[1][0];
                $parts[] = 'fence:' . $trimmed;
                continue;
            }

            if ($trimmed[0] === $fenceMarker) {
                $inFence = false;
                $parts[] = 'fence:' . $trimmed;
                continue;
            }
        }

        if ($inFence) {
            $parts[] = 'code:' . $line;
            continue;
        }

        if (preg_match('/^#{1,6}\s+\S/', $trimmed) === 1) {
            $parts[] = 'heading:' . $trimmed;
        } elseif (str_starts_with($trimmed, '|')) {
            $parts[] = 'row:' . preg_replace('/\s+/', ' ', $trimmed);
        }

        // Link targets are load-bearing wherever they appear, including in prose.
        if (preg_match_all('/\]\(([^)\s]+)/', $line, $lm) > 0) {
            foreach ($lm[1] as $target) {
                $parts[] = 'link:' . $target;
            }
        }
    }

    return implode("\n", $parts);
}

/** Truncated so that a 1,300-entry baseline stays reviewable in a diff. */
function shortHash(string $text): string
{
    return substr(hash('sha256', $text), 0, 16);
}

/**
 * @return array{content: string, structure: string}
 */
function hashesFor(string $raw): array
{
    $body = normalizeBody($raw);

    return ['content' => shortHash($body), 'structure' => shortHash(structureText($body))];
}

// --- Baseline ----------------------------------------------------------------

/**
 * Entries come straight from JSON, so they are `mixed` by construction — a
 * hand-edited baseline is exactly the case the caller re-validates.
 *
 * @return array{version: int, locales: list<string>, scope: list<string>, exclude: list<string>, entries: array<string, mixed>}
 */
function loadBaseline(string $root): array
{
    $defaults = [
        'version' => BASELINE_VERSION,
        'locales' => DEFAULT_LOCALES,
        'scope' => DEFAULT_SCOPE,
        'exclude' => DEFAULT_EXCLUDE,
        'entries' => [],
    ];

    $path = $root . '/' . BASELINE_FILENAME;

    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        exit(2);
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, BASELINE_FILENAME . ' is not valid JSON: ' . $e->getMessage() . "\n");
        exit(2);
    }

    if (!is_array($decoded)) {
        fwrite(STDERR, BASELINE_FILENAME . " must contain a JSON object.\n");
        exit(2);
    }

    foreach (['locales', 'scope', 'exclude'] as $key) {
        if (isset($decoded[$key]) && is_array($decoded[$key])) {
            $defaults[$key] = array_values($decoded[$key]);
        }
    }

    if (isset($decoded['entries']) && is_array($decoded['entries'])) {
        $defaults['entries'] = $decoded['entries'];
    }

    return $defaults;
}

/**
 * @param array{version: int, locales: list<string>, scope: list<string>, exclude: list<string>, entries: array<string, mixed>} $baseline
 */
function writeBaselineFile(string $root, array $baseline): void
{
    ksort($baseline['entries']);

    $json = json_encode([
        'version' => BASELINE_VERSION,
        'locales' => $baseline['locales'],
        'scope' => $baseline['scope'],
        'exclude' => $baseline['exclude'],
        'entries' => $baseline['entries'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    file_put_contents($root . '/' . BASELINE_FILENAME, $json . "\n");
}

// --- Discovery ---------------------------------------------------------------

/**
 * English files inside the scope, relative to docs/ (e.g. `howto/add-tag.md`).
 *
 * @param list<string> $scope
 * @param list<string> $exclude
 *
 * @return list<string>
 */
function englishFiles(string $root, array $scope, array $exclude): array
{
    $found = [];

    foreach ($scope as $dir) {
        foreach (glob($root . '/docs/' . $dir . '/*.md') ?: [] as $path) {
            $relative = $dir . '/' . basename($path);

            if (!in_array($relative, $exclude, true)) {
                $found[] = $relative;
            }
        }
    }

    sort($found);

    return $found;
}

/**
 * Locale files with no English counterpart — usually the leftovers of a rename.
 *
 * @param list<string> $english
 * @param list<string> $scope
 * @param list<string> $exclude
 *
 * @return list<array{locale: string, relative: string}>
 */
function orphans(string $root, string $locale, array $english, array $scope, array $exclude): array
{
    $known = array_flip($english);
    $found = [];

    foreach ($scope as $dir) {
        foreach (glob($root . '/docs/' . $locale . '/' . $dir . '/*.md') ?: [] as $path) {
            $relative = $dir . '/' . basename($path);

            if (!isset($known[$relative]) && !in_array($relative, $exclude, true)) {
                $found[] = ['locale' => $locale, 'relative' => $relative];
            }
        }
    }

    return $found;
}

// --- Git bootstrap -----------------------------------------------------------

/**
 * The English file's content at the commit that last touched the translation —
 * i.e. what the translator was looking at. Returns null when git cannot answer
 * (no repository, file added outside history, shallow clone).
 */
function englishAtTranslationTime(string $root, string $englishPath, string $localePath): ?string
{
    $commit = gitCapture($root, ['log', '-1', '--format=%H', '--', $localePath]);

    if ($commit === null || $commit === '') {
        return null;
    }

    return gitCapture($root, ['show', $commit . ':' . $englishPath]);
}

/**
 * @param list<string> $args
 */
function gitCapture(string $root, array $args): ?string
{
    $command = 'git -C ' . escapeshellarg($root);

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return null;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0 || $stdout === false) {
        return null;
    }

    return rtrim($stdout, "\n");
}

// --- Run ---------------------------------------------------------------------

if ($bootstrap && gitCapture($root, ['rev-parse', '--is-inside-work-tree']) !== 'true') {
    fwrite(STDERR, "--bootstrap needs a working git in {$root}, and found none.\n");
    fwrite(STDERR, "Run it on a host that has git; the app container does not ship one.\n");
    exit(2);
}

$baseline = loadBaseline($root);
$english = englishFiles($root, $baseline['scope'], $baseline['exclude']);

if ($english === []) {
    fwrite(STDERR, "No English documents found under {$root}/docs — check --root and the baseline scope.\n");
    exit(2);
}

$findings = [];
$entries = [];
$pairs = 0;
$assumed = 0;

foreach ($english as $relative) {
    $englishPath = 'docs/' . $relative;
    $raw = file_get_contents($root . '/' . $englishPath);

    if ($raw === false) {
        fwrite(STDERR, "Could not read {$englishPath}\n");
        exit(2);
    }

    $current = hashesFor($raw);

    foreach ($baseline['locales'] as $locale) {
        $localeRelative = 'docs/' . $locale . '/' . $relative;

        if (!is_file($root . '/' . $localeRelative)) {
            $findings[] = [
                'kind' => 'missing',
                'severity' => SEVERITY_ERROR,
                'file' => $localeRelative,
                'message' => sprintf('No %s translation of docs/%s.', $locale, $relative),
            ];
            continue;
        }

        $pairs++;

        if ($bootstrap) {
            $historic = englishAtTranslationTime($root, $englishPath, $localeRelative);

            if ($historic === null) {
                // Not resolvable from history (added outside it, or shallow clone).
                // Recorded as current and counted, so the assumption is visible.
                $assumed++;
                $entries[$relative][$locale] = $current;
            } else {
                $entries[$relative][$locale] = hashesFor($historic);
            }

            continue;
        }

        if ($writeBaseline) {
            $entries[$relative][$locale] = $current;
            continue;
        }

        $recorded = $baseline['entries'][$relative][$locale] ?? null;

        if (!is_array($recorded) || !isset($recorded['content'], $recorded['structure'])) {
            $findings[] = [
                'kind' => 'unmanaged',
                'severity' => SEVERITY_ERROR,
                'file' => $localeRelative,
                'message' => sprintf(
                    'No baseline entry for %s. Run `composer i18n:baseline` after verifying the translation is current.',
                    $localeRelative,
                ),
            ];
            continue;
        }

        if ($recorded['structure'] !== $current['structure']) {
            $findings[] = [
                'kind' => 'stale-structure',
                'severity' => SEVERITY_ERROR,
                'file' => $localeRelative,
                'message' => sprintf(
                    'docs/%s changed a heading, code block, table row or link since this translation. Update it, then run `composer i18n:baseline`.',
                    $relative,
                ),
            ];
            continue;
        }

        if ($recorded['content'] !== $current['content']) {
            $findings[] = [
                'kind' => 'stale-prose',
                'severity' => SEVERITY_WARN,
                'file' => $localeRelative,
                'message' => sprintf('docs/%s had prose-only edits since this translation.', $relative),
            ];
        }
    }
}

foreach ($baseline['locales'] as $locale) {
    foreach (orphans($root, $locale, $english, $baseline['scope'], $baseline['exclude']) as $orphan) {
        $findings[] = [
            'kind' => 'orphan',
            'severity' => SEVERITY_ERROR,
            'file' => 'docs/' . $orphan['locale'] . '/' . $orphan['relative'],
            'message' => sprintf('No English original for docs/%s — left over from a rename?', $orphan['relative']),
        ];
    }
}

if ($writeBaseline || $bootstrap) {
    $baseline['entries'] = $entries;
    writeBaselineFile($root, $baseline);

    $mode = $bootstrap ? 'bootstrapped from git history' : 'written from current sources';
    echo sprintf("%s %s: %d pairs across %d locales.\n", BASELINE_FILENAME, $mode, $pairs, count($baseline['locales']));

    if ($assumed > 0) {
        echo sprintf("%d pair(s) had no resolvable history and were recorded as current.\n", $assumed);
    }

    // A missing translation is still a real gap even while seeding.
    $errors = array_filter($findings, static fn (array $f): bool => $f['severity'] === SEVERITY_ERROR);

    foreach ($errors as $finding) {
        fwrite(STDERR, sprintf("%s  %s: %s\n", strtoupper($finding['severity']), $finding['file'], $finding['message']));
    }

    exit($errors === [] ? 0 : 1);
}

// --- Report ------------------------------------------------------------------

usort($findings, static function (array $a, array $b): int {
    return [$a['severity'] === SEVERITY_WARN ? 1 : 0, $a['file']] <=> [$b['severity'] === SEVERITY_WARN ? 1 : 0, $b['file']];
});

$errorCount = count(array_filter($findings, static fn (array $f): bool => $f['severity'] === SEVERITY_ERROR));
$warnCount = count($findings) - $errorCount;

if ($format === 'json') {
    echo json_encode([
        'ok' => $errorCount === 0,
        'pairs' => $pairs,
        'errors' => $errorCount,
        'warnings' => $warnCount,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

    exit($errorCount === 0 ? 0 : 1);
}

if ($findings === []) {
    echo sprintf("Translation freshness OK — %d pairs checked, no findings.\n", $pairs);
    exit(0);
}

foreach ($findings as $finding) {
    $stream = $finding['severity'] === SEVERITY_ERROR ? STDERR : STDOUT;
    fwrite($stream, sprintf("%-5s [%s] %s\n        %s\n", strtoupper($finding['severity']), $finding['kind'], $finding['file'], $finding['message']));
}

echo sprintf("\n%d pairs checked — %d error(s), %d warning(s).\n", $pairs, $errorCount, $warnCount);

exit($errorCount === 0 ? 0 : 1);
