#!/usr/bin/env bash
# bump-ft.sh — FT バージョンバンプ (FrameworkInfo.php + openapi.yaml)
#
# Usage:
#   bash tools/bump-ft.sh            # 現在のバージョンから自動 +1（推奨）
#   bash tools/bump-ft.sh 1.5.241    # バージョンを明示指定
#
# CHANGELOG.md は内容を伴うため手動追記してください。
# その後 git add して composer check → コミットしてください。

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FRAMEWORK_INFO="${REPO_ROOT}/src/FrameworkInfo.php"
OPENAPI_YAML="${REPO_ROOT}/docs/openapi/openapi.yaml"

# 現在のバージョンを取得
if ! grep -q "public const string VERSION" "$FRAMEWORK_INFO"; then
  echo "Error: ${FRAMEWORK_INFO} に VERSION が見つかりません" >&2
  exit 1
fi
CURRENT_VER=$(grep "public const string VERSION" "$FRAMEWORK_INFO" | grep -oP "'[^']+'" | tr -d "'")

# 引数なし → 自動インクリメント（patch +1）
if [[ $# -eq 0 ]]; then
  MAJOR="${CURRENT_VER%%.*}"
  REST="${CURRENT_VER#*.}"
  MINOR="${REST%%.*}"
  PATCH="${REST##*.}"
  NEW_VER="${MAJOR}.${MINOR}.$((PATCH + 1))"
  echo "自動インクリメント: ${CURRENT_VER} → ${NEW_VER}"
else
  NEW_VER="$1"
  # バリデーション: x.y.z 形式か確認
  if ! [[ "$NEW_VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: バージョンは x.y.z 形式で指定してください (例: 1.5.241)" >&2
    exit 1
  fi
  echo "バンプ: ${CURRENT_VER} → ${NEW_VER}"
fi

sed -i "s/public const string VERSION = '.*'/public const string VERSION = '${NEW_VER}'/" "$FRAMEWORK_INFO"
sed -i "s/^  version: .*/  version: ${NEW_VER}/" "$OPENAPI_YAML"

echo ""
echo "✅ バンプ完了"
echo "   src/FrameworkInfo.php     → ${NEW_VER}"
echo "   docs/openapi/openapi.yaml → ${NEW_VER}"
echo ""
echo "次のステップ:"
echo "  1. CHANGELOG.md に [${NEW_VER}] エントリを手動追記"
echo "  2. docker compose run --rm app composer check"
echo "  3. git add . && git commit"
