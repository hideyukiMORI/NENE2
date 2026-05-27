#!/usr/bin/env bash
# uncovered-fts.sh — howto 未カバーの FT プロジェクト一覧を表示
#
# Usage:
#   bash tools/uncovered-fts.sh           # 未カバー一覧
#   bash tools/uncovered-fts.sh --all     # 全件（カバー済み・未カバー両方）
#   bash tools/uncovered-fts.sh --check <name>  # 特定 FT がカバー済みか確認
#
# 判定基準: docs/howto/ 内のいずれかのファイルに以下のいずれかが含まれれば
#           カバー済みとみなす:
#   - 新形式: "NENE2-FT/<name>"
#   - 旧形式: "Pattern proven by FT<number> <name>"

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FT_DIR="${REPO_ROOT}/../NENE2-FT"
HOWTO_DIR="${REPO_ROOT}/docs/howto"

if [[ ! -d "$FT_DIR" ]]; then
  echo "Error: FT ディレクトリが見つかりません: ${FT_DIR}" >&2
  exit 1
fi

# カバー済み FT 名のリスト (ソート済み)
covered() {
  {
    # 新形式: FT reference: FTxxx (NENE2-FT/<name>) または単純な NENE2-FT/<name> 参照
    # i18nlog (数字入り) / eventstore / projtrack / softdelete も対象
    grep -rh "NENE2-FT/" "$HOWTO_DIR" 2>/dev/null \
      | grep -oP 'NENE2-FT/\K[a-z0-9]*(log|store|track|delete)'

    # 旧形式: Pattern proven by FT<number> <name>
    grep -rh "Pattern proven by FT" "$HOWTO_DIR" 2>/dev/null \
      | grep -oP 'Pattern proven by FT\d+ \K[a-z0-9]*(log|store|track|delete)'
  } | sort -u
}

# 全 FT プロジェクト名のリスト (ソート済み)
# - *log パターン (例: cartlog, ratelog)
# - i18nlog (数字を含む例外)
# - log 以外の FT プロジェクト (eventstore / projtrack / softdelete)
all_fts() {
  ls "$FT_DIR" | grep -E '^[a-z0-9]*log$|^(eventstore|projtrack|softdelete)$' | sort
}

MODE="${1:-}"

case "$MODE" in
  --all)
    echo "=== 全 FT プロジェクト（カバー状況）==="
    covered_list=$(covered)
    while IFS= read -r ft; do
      if echo "$covered_list" | grep -qx "$ft"; then
        echo "  ✅  $ft"
      else
        echo "  ❌  $ft"
      fi
    done < <(all_fts)
    ;;

  --check)
    if [[ $# -lt 2 ]]; then
      echo "Usage: $0 --check <ft-name>" >&2
      exit 1
    fi
    TARGET="$2"
    if covered | grep -qx "$TARGET"; then
      echo "✅ ${TARGET} はカバー済みです"
    else
      echo "❌ ${TARGET} は未カバーです"
    fi
    ;;

  "")
    echo "=== howto 未カバー FT ==="
    uncovered=$(comm -23 <(all_fts) <(covered))
    if [[ -z "$uncovered" ]]; then
      echo "  (すべての FT がカバー済みです)"
    else
      echo "$uncovered" | sed 's/^/  /'
      echo ""
      COUNT=$(echo "$uncovered" | wc -l | tr -d ' ')
      echo "合計 ${COUNT} 件未カバー"
    fi
    ;;

  *)
    echo "Usage: $0 [--all | --check <name>]" >&2
    exit 1
    ;;
esac
