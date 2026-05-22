#!/usr/bin/env bash
# new-ft.sh — scaffold a new FT project under ../NENE2-FT/
#
# Usage: tools/new-ft.sh <ft-number> <dirname>
# Example: tools/new-ft.sh 171 newfeaturelog

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NENE2_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FT_BASE="$(cd "${NENE2_DIR}/../NENE2-FT" && pwd)"
REGISTRY="${NENE2_DIR}/docs/ft-registry.md"

# ── argument check ────────────────────────────────────────────────────────────
if [[ $# -lt 2 ]]; then
    echo "Usage: tools/new-ft.sh <ft-number> <dirname>"
    echo "  e.g. tools/new-ft.sh 171 newfeaturelog"
    exit 1
fi

FT_NUM="$1"
DIRNAME="$2"
TARGET="${FT_BASE}/${DIRNAME}"

# ── registry collision check ──────────────────────────────────────────────────
if grep -q "\b${DIRNAME}\b" "${REGISTRY}" 2>/dev/null; then
    echo "ERROR: '${DIRNAME}' is already registered in docs/ft-registry.md"
    echo "  Check the registry and pick a different name."
    grep "${DIRNAME}" "${REGISTRY}"
    exit 1
fi

# ── existing directory check ──────────────────────────────────────────────────
if [[ -d "${TARGET}" ]]; then
    echo "ERROR: ${TARGET} already exists."
    exit 1
fi

# ── find vendor source (most recently modified FT project with vendor/) ────────
VENDOR_SRC=""
VENDOR_MTIME=0
for d in "${FT_BASE}"/*/vendor; do
    [[ -d "$d" ]] || continue
    mt=$(stat -c "%Y" "$d" 2>/dev/null || stat -f "%m" "$d" 2>/dev/null || echo 0)
    if (( mt > VENDOR_MTIME )); then
        VENDOR_MTIME=$mt
        VENDOR_SRC="$d"
    fi
done

if [[ -z "${VENDOR_SRC}" ]]; then
    echo "ERROR: No vendor/ directory found in any FT project under ${FT_BASE}"
    echo "  Run 'composer install' in an existing FT project first."
    exit 1
fi

# ── create directory structure ────────────────────────────────────────────────
echo "Creating ${TARGET}..."
mkdir -p "${TARGET}/src" "${TARGET}/tests" "${TARGET}/database"

# ── copy vendor ───────────────────────────────────────────────────────────────
VENDOR_PROJECT="$(dirname "${VENDOR_SRC}")"
echo "Copying vendor from $(basename "${VENDOR_PROJECT}")..."
cp -r "${VENDOR_SRC}" "${TARGET}/vendor"

# ── write skeleton files ──────────────────────────────────────────────────────
NAMESPACE="$(echo "${DIRNAME:0:1}" | tr '[:lower:]' '[:upper:]')${DIRNAME:1}"

cat > "${TARGET}/composer.json" <<COMPOSER
{
    "name": "${DIRNAME}/${DIRNAME}",
    "description": "FT${FT_NUM}: TODO — add description",
    "type": "project",
    "require": {
        "php": ">=8.4",
        "hideyukimori/nene2": "^1.5",
        "nyholm/psr7": "^1.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "${NAMESPACE}\\\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "${NAMESPACE}\\\\Tests\\\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit --testdox",
        "analyse": "vendor/bin/phpstan analyse --level=8 src tests",
        "cs": "vendor/bin/php-cs-fixer fix --dry-run --diff",
        "cs:fix": "vendor/bin/php-cs-fixer fix",
        "check": ["@cs", "@analyse", "@test"]
    }
}
COMPOSER

cat > "${TARGET}/phpunit.xml" <<PHPUNIT
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="${DIRNAME}">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
PHPUNIT

cat > "${TARGET}/phpstan.neon" <<PHPSTAN
parameters:
    level: 8
    paths:
        - src
        - tests
PHPSTAN

cat > "${TARGET}/.php-cs-fixer.php" <<CSFIXER
<?php

\$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true])
    ->setFinder(\$finder);
CSFIXER

cat > "${TARGET}/database/schema.sql" <<SCHEMA
-- FT${FT_NUM}: TODO — add schema
SCHEMA

# ── dump-autoload ─────────────────────────────────────────────────────────────
echo "Running dump-autoload..."
(cd "${TARGET}" && composer dump-autoload --quiet)

# ── done ──────────────────────────────────────────────────────────────────────
echo ""
echo "✓ ${TARGET} created"
echo ""
echo "Next steps:"
echo "  1. Edit ${TARGET}/composer.json  (description)"
echo "  2. Edit ${TARGET}/database/schema.sql"
echo "  3. Add source files under ${TARGET}/src/"
echo "  4. Add tests under ${TARGET}/tests/"
echo "  5. Register in docs/ft-registry.md:"
echo "     | FT${FT_NUM} | ${DIRNAME} | TODO — theme |"
