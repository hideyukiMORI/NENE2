#!/usr/bin/env bash
# aggregate-dx-trials.sh — DX Trial の TRIAL_LOG.md フロントマターを集計する
#
# Usage:
#   bash tools/aggregate-dx-trials.sh              # 全 Trial を集計
#   bash tools/aggregate-dx-trials.sh 27           # Trial 27 のみ
#   bash tools/aggregate-dx-trials.sh 27 30        # Trial 27〜30 を集計
#
# 前提: TRIAL_LOG.md は tools/setup-dx-trial.sh が生成した YAML フロントマター付きのもの
#       Trial 01〜26 はフロントマターなし（"(no frontmatter)" と表示）

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE="$(cd "${SCRIPT_DIR}/../../NENE2-FT" && pwd)"

START="${1:-}"
END="${2:-}"

# フロントマターから特定フィールドを1行で抽出するヘルパー
extract() {
  local file="$1"
  local field="$2"
  local default="${3:-}"
  local val
  val=$(grep -m1 "^${field}:" "$file" 2>/dev/null | sed -E "s/^${field}:[[:space:]]*//" | sed 's/[[:space:]]*#.*//' | tr -d '"'"'" | xargs 2>/dev/null || true)
  echo "${val:-$default}"
}

# bool 値を記号に変換
bool_sym() { [[ "$1" == "true" ]] && echo "✓" || echo "-"; }

# ヘッダー出力
print_header() {
  printf "%-6s %-4s %-18s %5s %5s %-8s %-4s %-4s %-4s  %s\n" \
    "Trial" "P" "App" "Tests" "Asrts" "PHPStan" "TX" "Enum" "VO" "Notes"
  printf '%0.s-' {1..80}; echo
}

process_trial() {
  local num="$1"
  local padded
  padded=$(printf "%02d" "$num" 2>/dev/null || echo "$num")

  for persona in A B C; do
    local dir="${BASE}/dx-trial-${padded}-persona-${persona}"
    local log="${dir}/TRIAL_LOG.md"

    if [[ ! -d "$dir" ]]; then
      continue
    fi

    if [[ ! -f "$log" ]]; then
      printf "%-6s %-4s %-18s %5s %5s %-8s %-4s %-4s %-4s  %s\n" \
        "$padded" "$persona" "(no log)" "-" "-" "-" "-" "-" "-" ""
      continue
    fi

    # フロントマターの有無チェック（先頭行が "---" か）
    local first_line
    first_line=$(head -1 "$log")
    if [[ "$first_line" != "---" ]]; then
      printf "%-6s %-4s %-18s %5s %5s %-8s %-4s %-4s %-4s  %s\n" \
        "$padded" "$persona" "(no frontmatter)" "-" "-" "-" "-" "-" "-" ""
      continue
    fi

    local app tests assertions phpstan tx enum vo notes
    app=$(extract "$log" "app" "")
    tests=$(extract "$log" "tests" "0")
    assertions=$(extract "$log" "assertions" "0")
    phpstan=$(extract "$log" "phpstan" "-")
    tx=$(bool_sym "$(extract "$log" "tx" "false")")
    enum=$(bool_sym "$(extract "$log" "enum" "false")")
    vo=$(bool_sym "$(extract "$log" "vo" "false")")
    notes=$(extract "$log" "notes" "")

    local ps_s
    [[ "$phpstan" == "pass" ]] && ps_s="✅pass" || ps_s="❌${phpstan}"

    printf "%-6s %-4s %-18s %5s %5s %-8s %-4s %-4s %-4s  %s\n" \
      "$padded" "$persona" "${app:0:18}" "$tests" "$assertions" \
      "$ps_s" "$tx" "$enum" "$vo" "${notes:0:40}"
  done
}

# 対象 Trial 番号の列挙
get_trial_numbers() {
  if [[ -z "$START" ]]; then
    # 全件: 実在するディレクトリから番号を抽出
    ls -d "${BASE}"/dx-trial-*-persona-A 2>/dev/null \
      | grep -oP 'dx-trial-\K[0-9]+(?=-persona-A)' \
      | sort -n
  elif [[ -z "$END" ]]; then
    echo "$START"
  else
    seq "$START" "$END"
  fi
}

print_header
found=0
while IFS= read -r num; do
  process_trial "$num"
  found=$((found + 3))
done < <(get_trial_numbers)

printf '%0.s-' {1..80}; echo

if [[ $found -eq 0 ]]; then
  echo "（対象の Trial ディレクトリが見つかりませんでした）"
  echo "  期待パス: ${BASE}/dx-trial-NN-persona-{A,B,C}/"
else
  echo "計: ${found} ディレクトリ確認"
fi
