#!/usr/bin/env bash
# start-ft.sh — FT 1件の作業開始を自動化する
#
# やること:
#   1. GitHub Issue を作成
#   2. ブランチを作成（docs/<issue>-ft<N>-<name>）
#   3. バージョンを自動バンプ（bump-ft.sh --auto）
#   4. CHANGELOG.md に空エントリを挿入（手入力の場所をガイド）
#
# Usage:
#   bash tools/start-ft.sh <ft-number> <ft-name> <howto-topic> [issue-title]
#
# 例:
#   bash tools/start-ft.sh 350 workflowlog "ステートマシン型ワークフロー API"
#   bash tools/start-ft.sh 351 auditlog "監査ログ API" "FT351 auditlog howto追加"
#
# 作業完了後は通常の git commit / push / gh pr create を行うこと。

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

FT_NUM="${1:-}"
FT_NAME="${2:-}"
TOPIC="${3:-}"
ISSUE_TITLE="${4:-}"

if [[ -z "$FT_NUM" || -z "$FT_NAME" || -z "$TOPIC" ]]; then
  echo "Usage: $0 <ft-number> <ft-name> <topic> [issue-title]" >&2
  echo "  ft-number : FT 番号 (例: 350)" >&2
  echo "  ft-name   : FT ディレクトリ名 (例: workflowlog)" >&2
  echo "  topic     : howto テーマの説明 (例: \"ステートマシン型ワークフロー API\")" >&2
  exit 1
fi

# デフォルトの Issue タイトル
if [[ -z "$ISSUE_TITLE" ]]; then
  ISSUE_TITLE="docs(howto): FT${FT_NUM} ${FT_NAME} ${TOPIC} howto 追加"
fi

cd "$REPO_ROOT"

# ── 1. main が最新か確認 ──────────────────────────────────────────────────────
CURRENT_BRANCH=$(git branch --show-current)
if [[ "$CURRENT_BRANCH" != "main" ]]; then
  echo "⚠️  現在のブランチ: ${CURRENT_BRANCH}"
  echo "   main に切り替えてから実行してください"
  exit 1
fi

git pull --quiet origin main
echo "✅ main 最新"

# ── 2. GitHub Issue 作成 ──────────────────────────────────────────────────────
echo "Issue 作成中..."
ISSUE_URL=$(gh issue create \
  --title "${ISSUE_TITLE}" \
  --body "## 概要

FT${FT_NUM} \`${FT_NAME}\` の howto を作成する。

## テーマ

${TOPIC}

## 作業内容

- \`docs/howto/\` に新規または更新
- \`CHANGELOG.md\` に追記
- \`docs/openapi/openapi.yaml\` バージョンバンプ
- \`src/FrameworkInfo.php\` バージョンバンプ

## 参照

- \`../NENE2-FT/${FT_NAME}/\`" \
  2>&1)

ISSUE_NUM=$(echo "$ISSUE_URL" | grep -oP '(?<=/issues/)\d+$')
echo "✅ Issue #${ISSUE_NUM} 作成: ${ISSUE_URL}"

# ── 3. ブランチ作成 ───────────────────────────────────────────────────────────
BRANCH="docs/${ISSUE_NUM}-ft${FT_NUM}-${FT_NAME}"
git checkout -b "$BRANCH"
echo "✅ ブランチ: ${BRANCH}"

# ── 4. バージョンバンプ ───────────────────────────────────────────────────────
bash "${SCRIPT_DIR}/bump-ft.sh"

# ── 5. CHANGELOG に空エントリを挿入 ──────────────────────────────────────────
NEW_VER=$(grep "public const string VERSION" "${REPO_ROOT}/src/FrameworkInfo.php" \
  | grep -oP "'[^']+'" | tr -d "'")
TODAY=$(date +%Y-%m-%d)
CHANGELOG="${REPO_ROOT}/CHANGELOG.md"

# [Unreleased] セクションの直後に新バージョンエントリを挿入
ENTRY="## [${NEW_VER}] — ${TODAY}\n\n### Added\n- \`docs/howto/TODO.md\` — FT${FT_NUM} ${FT_NAME}: ${TOPIC} (#${ISSUE_NUM})\n\n---\n"

# "## [Unreleased]" と "---" の間に挿入
python3 - "$CHANGELOG" "$ENTRY" <<'PYEOF'
import sys, re

path = sys.argv[1]
entry = sys.argv[2]

with open(path, 'r') as f:
    content = f.read()

# "[Unreleased]\n\n---\n" の直後に挿入
new_content = re.sub(
    r'(## \[Unreleased\]\n\n---\n)',
    r'\1\n' + entry,
    content,
    count=1
)

with open(path, 'w') as f:
    f.write(new_content)
PYEOF

echo "✅ CHANGELOG.md に [${NEW_VER}] エントリを挿入"
echo ""
echo "════════════════════════════════════════"
echo "  次のステップ:"
echo "  1. docs/howto/<topic>.md を作成・編集"
echo "     → 先頭に frontmatter 必須 (title/category/tags/difficulty)"
echo "       スキーマ: docs/development/howto-frontmatter.md"
echo "  2. CHANGELOG.md の TODO.md を実際のファイル名に修正"
echo "  3. docker compose run --rm app composer howto:index   # README/by-tag.md 再生成（コミット対象）"
echo "  4. docker compose run --rm app composer check"
echo "  5. git add . && git commit -m 'docs(howto): ${TOPIC} (#${ISSUE_NUM})'"
echo "  6. git push && gh pr create"
echo "  ※ frontmatter 未付与 / howto:index 未再生成は CI で fail する (#1331)"
echo "════════════════════════════════════════"
