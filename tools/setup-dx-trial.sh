#!/usr/bin/env bash
# setup-dx-trial.sh — DX 試験サンドボックスをセットアップする
#
# 使い方:
#   bash tools/setup-dx-trial.sh <trial-num> <namespace> [src-vendor-dir]
#
# 例:
#   bash tools/setup-dx-trial.sh 07 Shop
#   bash tools/setup-dx-trial.sh 07 Shop /home/xi/docker/NENE2-FT/dx-trial-01-persona-A/vendor
#
# 作成されるディレクトリ:
#   ../NENE2-FT/dx-trial-<num>-persona-A/
#   ../NENE2-FT/dx-trial-<num>-persona-B/
#   ../NENE2-FT/dx-trial-<num>-persona-C/
#
# 注意: vendor は cp -al（ハードリンク）で高速コピーするが、
#       composer が書き換える vendor/composer/ と vendor/autoload.php だけは
#       実コピーにして各ディレクトリを独立させる。

set -euo pipefail

TRIAL_NUM="${1:-}"
NS="${2:-}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
BASE="${REPO_ROOT}/../NENE2-FT"

# デフォルトの vendor 供給元（既存の Trial から流用）
DEFAULT_VENDOR="${BASE}/dx-trial-01-persona-A/vendor"
SRC_VENDOR="${3:-${DEFAULT_VENDOR}}"

if [[ -z "$TRIAL_NUM" || -z "$NS" ]]; then
  echo "Usage: $0 <trial-num> <namespace> [src-vendor-dir]" >&2
  echo "  trial-num : 2桁の番号 (例: 07)" >&2
  echo "  namespace : PHP 名前空間 (例: Shop, Tasks, Blog)" >&2
  exit 1
fi

if [[ ! -d "$SRC_VENDOR" ]]; then
  echo "Error: vendor source not found: ${SRC_VENDOR}" >&2
  exit 1
fi

for PERSONA in A B C; do
  DIR="${BASE}/dx-trial-${TRIAL_NUM}-persona-${PERSONA}"

  echo "--- Persona ${PERSONA}: ${DIR}"
  mkdir -p "${DIR}/src/${NS}" "${DIR}/tests/${NS}" "${DIR}/database"

  # composer.json
  cat > "${DIR}/composer.json" <<EOF
{
    "name": "ft/dx-trial-${TRIAL_NUM}",
    "description": "DX Trial ${TRIAL_NUM} persona ${PERSONA}",
    "type": "project",
    "minimum-stability": "dev",
    "require": {
        "hideyukimori/nene2": "^1.5"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.2@dev",
        "phpstan/phpstan": "2.2.x-dev",
        "friendsofphp/php-cs-fixer": "dev-master"
    },
    "autoload": {
        "psr-4": {
            "${NS}\\\\": "src/${NS}/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "${NS}\\\\Tests\\\\": "tests/"
        }
    },
    "scripts": {
        "test": "php8.4 vendor/bin/phpunit --testdox",
        "analyse": "php8.4 vendor/bin/phpstan analyse --level=8 --memory-limit=512M src tests",
        "check": ["@test", "@analyse"]
    }
}
EOF

  # phpunit.xml
  cat > "${DIR}/phpunit.xml" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="${NS}">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
EOF

  # vendor: ハードリンクで高速コピー
  cp -al "${SRC_VENDOR}" "${DIR}/vendor"

  # vendor/composer/ だけ実コピーで上書き（inode 独立）
  # → 各ディレクトリが composer dump-autoload を独立して実行できる
  # cp -al で同一 inode になっている場合に備えて一時ディレクトリ経由でコピー
  TMP_COMPOSER=$(mktemp -d)
  cp -r "${SRC_VENDOR}/composer/." "${TMP_COMPOSER}/"
  rm -rf "${DIR}/vendor/composer"
  mv "${TMP_COMPOSER}" "${DIR}/vendor/composer"

  # vendor/autoload.php も独立させる
  TMP_AUTOLOAD=$(mktemp)
  cp "${SRC_VENDOR}/autoload.php" "${TMP_AUTOLOAD}"
  mv "${TMP_AUTOLOAD}" "${DIR}/vendor/autoload.php"

  # この名前空間用に autoload を再生成
  cd "${DIR}"
  composer dump-autoload -q
  cd - > /dev/null

  echo "    ✅ done (vendor: hard-link + composer/ independent)"
done

echo ""
echo "✅ Trial ${TRIAL_NUM} (${NS}) — 3 sandboxes ready at ${BASE}/dx-trial-${TRIAL_NUM}-persona-{A,B,C}/"
