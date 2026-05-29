<?php

/**
 * Validates the YAML frontmatter of howto guides against the schema documented
 * in docs/development/howto-frontmatter.md.
 *
 * During the Phase B rollout, guides without a frontmatter block are allowed
 * and reported as "unannotated"; guides that have a block are validated
 * strictly. Pass --require-all to fail when any guide is still unannotated
 * (used once every guide is annotated).
 *
 * Usage: php tools/validate-howto-frontmatter.php [--require-all]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$root       = dirname(__DIR__);
$howtoDir   = $root . '/docs/howto';
$requireAll = in_array('--require-all', $argv, true);

const ALLOWED_CATEGORIES = [
    'getting-started',
    'auth',
    'security',
    'database',
    'api-design',
    'infrastructure',
    'product',
];

const ALLOWED_DIFFICULTY = ['beginner', 'intermediate', 'advanced'];

const REQUIRED_FIELDS = ['title', 'category', 'tags', 'difficulty'];
const OPTIONAL_FIELDS = ['related', 'ft'];

/** Files in the howto directory that are indexes, not guides. */
const NON_GUIDE_FILES = ['README.md', 'by-tag.md'];

/**
 * Extract the raw YAML frontmatter block from a Markdown file, or null when the
 * file does not open with a `---` fence.
 */
function extractFrontmatter(string $contents): ?string
{
    if (!str_starts_with($contents, "---\n") && !str_starts_with($contents, "---\r\n")) {
        return null;
    }
    // Match the opening fence and the next closing fence on its own line.
    if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $contents, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * Build the set of valid related-link targets (every howto slug on disk).
 *
 * @return array<string, true>
 */
function knownSlugs(string $howtoDir): array
{
    $slugs = [];
    foreach (glob($howtoDir . '/*.md') ?: [] as $file) {
        if (!in_array(basename($file), NON_GUIDE_FILES, true)) {
            $slugs[basename($file, '.md')] = true;
        }
    }

    return $slugs;
}

/**
 * Validate one parsed frontmatter map, returning a list of human-readable error
 * messages (empty when valid).
 *
 * @param  array<string, mixed> $fm
 * @param  array<string, true>  $slugs
 * @return list<string>
 */
function validateFrontmatter(array $fm, array $slugs): array
{
    $errors = [];

    foreach (REQUIRED_FIELDS as $field) {
        if (!array_key_exists($field, $fm)) {
            $errors[] = "missing required field `{$field}`";
        }
    }

    $allowedKeys = [...REQUIRED_FIELDS, ...OPTIONAL_FIELDS];
    foreach (array_keys($fm) as $key) {
        if (!in_array($key, $allowedKeys, true)) {
            $errors[] = "unknown field `{$key}`";
        }
    }

    if (isset($fm['title']) && (!is_string($fm['title']) || trim($fm['title']) === '')) {
        $errors[] = '`title` must be a non-empty string';
    }

    if (isset($fm['category']) && !in_array($fm['category'], ALLOWED_CATEGORIES, true)) {
        $errors[] = '`category` must be one of: ' . implode(', ', ALLOWED_CATEGORIES);
    }

    if (isset($fm['difficulty']) && !in_array($fm['difficulty'], ALLOWED_DIFFICULTY, true)) {
        $errors[] = '`difficulty` must be one of: ' . implode(', ', ALLOWED_DIFFICULTY);
    }

    if (isset($fm['tags'])) {
        if (!is_array($fm['tags']) || $fm['tags'] === [] || array_is_list($fm['tags']) === false) {
            $errors[] = '`tags` must be a non-empty list';
        } else {
            if (count($fm['tags']) > 6) {
                $errors[] = '`tags` must have at most 6 entries';
            }
            foreach ($fm['tags'] as $tag) {
                if (!is_string($tag) || preg_match('/^[a-z0-9-]+$/', $tag) !== 1) {
                    $errors[] = 'tag `' . var_export($tag, true) . '` must be lowercase kebab-case';
                }
            }
        }
    }

    if (isset($fm['related'])) {
        if (!is_array($fm['related']) || array_is_list($fm['related']) === false) {
            $errors[] = '`related` must be a list';
        } else {
            foreach ($fm['related'] as $slug) {
                if (!is_string($slug) || !isset($slugs[$slug])) {
                    $errors[] = 'related `' . var_export($slug, true) . '` does not match an existing howto';
                }
            }
        }
    }

    if (isset($fm['ft']) && (!is_string($fm['ft']) || preg_match('/^FT\d+$/', $fm['ft']) !== 1)) {
        $errors[] = '`ft` must look like `FT123`';
    }

    return $errors;
}

// -------------------------------------------------------------------------
// Walk every guide
// -------------------------------------------------------------------------

$slugs       = knownSlugs($howtoDir);
$files       = glob($howtoDir . '/*.md') ?: [];
$files       = array_filter($files, static fn (string $f): bool => !in_array(basename($f), NON_GUIDE_FILES, true));
sort($files, SORT_STRING);

$annotated   = 0;
$unannotated = [];
$hasError    = false;

foreach ($files as $file) {
    $name     = basename($file);
    $contents = (string) file_get_contents($file);
    $raw      = extractFrontmatter($contents);

    if ($raw === null) {
        $unannotated[] = $name;
        continue;
    }

    try {
        $parsed = Yaml::parse($raw);
    } catch (ParseException $e) {
        echo "✗ {$name}: invalid YAML — {$e->getMessage()}\n";
        $hasError = true;
        continue;
    }

    if (!is_array($parsed)) {
        echo "✗ {$name}: frontmatter is not a mapping\n";
        $hasError = true;
        continue;
    }

    /** @var array<string, mixed> $parsed */
    $errors = validateFrontmatter($parsed, $slugs);
    if ($errors !== []) {
        $hasError = true;
        foreach ($errors as $error) {
            echo "✗ {$name}: {$error}\n";
        }
        continue;
    }

    $annotated++;
}

$total = count($files);
echo sprintf("\nhowto frontmatter: %d/%d annotated, %d unannotated\n", $annotated, $total, count($unannotated));

if ($requireAll && $unannotated !== []) {
    echo "✗ --require-all: the following guides have no frontmatter:\n";
    foreach ($unannotated as $name) {
        echo "  - {$name}\n";
    }
    $hasError = true;
}

if ($hasError) {
    echo "\nFrontmatter validation failed.\n";
    exit(1);
}

echo "Frontmatter validation passed.\n";
