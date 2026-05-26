# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.
**引き継ぎドキュメントとして機能する — セッション開始時に必ず読むこと。**

---

## 🔖 FT ループ引き継ぎ状態（毎 5FT 更新）

| 項目 | 値 |
|------|-----|
| 最終完了 FT | **FT269** (`NENE2-FT/cartlog` — ショッピングカート API) |
| 現在の VERSION | **1.5.240** |
| 次の FT | **FT270** |
| 次の ATK 回 | **FT272**（4件ごと: FT252, 256, 260, 264, 268 ✅） |
| 次の VULN 回 | **FT273**（6件ごと: FT249, 255, 261, 267 ✅） |
| 進行中ブランチ | なし（main クリーン） |

---

## 最近完了した FT（直近 10 件）

| FT | タイプ | howto | VERSION |
|----|--------|-------|---------|
| FT260 | ATK | `webhook-signature-verification.md` | 1.5.231 |
| FT261 | VULN | `jwt-authentication.md` 更新 | 1.5.232 |
| FT262 | 通常 | `multi-currency-money-ledger.md` | 1.5.233 |
| FT263 | 通常 | `emoji-reactions-toggle.md` | 1.5.234 |
| FT264 | ATK | `sql-injection-defence.md` | 1.5.235 |
| FT265 | 通常 | `url-bookmark-api.md` | 1.5.236 |
| FT266 | 通常 | `api-key-management.md` 更新 | 1.5.237 |
| FT267 | VULN | `encrypted-field-storage.md` 更新 | 1.5.238 |
| FT268 | ATK | `audit-trail.md` 更新 | 1.5.239 |
| FT269 | 通常 | `shopping-cart-api.md` | 1.5.240 |

---

## ⚠️ 最優先 TODO（FT270 開始前に対処）

### P1: howto 重複ファイルの解消

**背景**: FT269 で `shopping-cart-api.md` を新規作成したが、同じ `NENE2-FT/cartlog` を参照する古い `shopping-cart.md` が既に存在していた。

**状況**:
- `docs/howto/shopping-cart.md` — FT155 相当の古い howto（`NENE2-FT/` 参照なし、日本語タイトル）
- `docs/howto/shopping-cart-api.md` — FT269 で新規作成（`NENE2-FT/cartlog` 参照あり、英語）

**対応方針**:
1. `shopping-cart.md` の内容を確認し、`shopping-cart-api.md` に取り込む価値があるか判断
2. `shopping-cart-api.md` に FT155 の参照も追加（FT155 + FT269 の両方をカバー）
3. `shopping-cart.md` を削除（またはリダイレクト用の最小ファイルに置き換え）

**確認コマンド**:
```bash
wc -l docs/howto/shopping-cart.md docs/howto/shopping-cart-api.md
diff <(head -20 docs/howto/shopping-cart.md) <(head -20 docs/howto/shopping-cart-api.md)
```

---

### P2: `uncovered-fts.sh` の精度問題 — 古い howto への FT 参照追加

**背景**: `uncovered-fts.sh` は `docs/howto/` 内の `NENE2-FT/<name>` 形式の参照で判定する。この形式がない howto はカバー済みでも「未カバー」と誤表示される。

**現状の数字**:
```
全 howto: 192 件
新形式（NENE2-FT/）: 82 件  ← uncovered-fts.sh が検出できる
旧形式（Pattern proven by FTxxx）: 4 件  ← 検出できない
完全参照なし: 110 件  ← 検出できない
```

**110件の内訳（要調査）**:
- フレームワーク概念ガイド（`add-health-check.md`, `add-rate-limiting.md` 等） → FT 参照不要
- 旧 FT howto（`access-token-management.md`, `activity-feed.md` 等） → FT 参照が必要

**対応方針（2択）**:

**Option A（推奨）: スクリプトを賢くする**
`uncovered-fts.sh` に旧形式 `Pattern proven by FTxxx <appname>` の検出を追加:
```bash
# 追加する検出ロジック
grep -rh "Pattern proven by FT" docs/howto/ \
  | grep -oP 'FT\d+ \K[a-z]+log'  # "FT188 verifylog" → "verifylog"
```

**Option B: 古い howto に参照を一括追加**
`access-token-management.md` など FT 対応ファイルに `NENE2-FT/tokenlog` 形式の参照ヘッダーを追加する。量が多い（推定 50〜80 件）ため工数大。

**推奨**: まず Option A でスクリプト精度を上げ、必要に応じて Option B を後から実施。

**作業の入口**:
```bash
# 旧形式の参照がある howto を確認
grep -rl "Pattern proven by FT" docs/howto/

# 参照が完全にないが FT 対応と思われるファイルを特定
grep -rL 'NENE2-FT/\|Pattern proven by FT' docs/howto/*.md | grep -v "add-\|README"
```

---

### P3: FT 番号 → プロジェクト名の台帳作成

**背景**: 「FT268 って何だっけ？」と遡りたいとき、howto ファイルを全文 grep するしかない。

**対応**: `docs/howto/ft-registry.md` を作成し、FT番号・プロジェクト名・howto ファイル名・タイプ・VERSIONを一覧化する。

**フォーマット案**:
```markdown
| FT | プロジェクト | howto ファイル | タイプ | VERSION |
|----|------------|--------------|--------|---------|
| FT267 | encryptlog | encrypted-field-storage.md | VULN | 1.5.238 |
| FT268 | auditlog | audit-trail.md | ATK | 1.5.239 |
| FT269 | cartlog | shopping-cart-api.md | 通常 | 1.5.240 |
```

既存の howto ヘッダー（`FT reference: FTxxx (NENE2-FT/xxx)`）から自動生成できる。

**生成コマンド**（台帳の素材として）:
```bash
grep -rh "FT reference.*NENE2-FT/" docs/howto/ \
  | grep -oP 'FT\d+.*NENE2-FT/[a-z]+log'
```

---

### P4: ATK / VULN セクションのテンプレート

**背景**: ATK/VULN を書くたびに「ATK-01〜12、サマリー表、スコア行」を頭で再現している。

**対応**: CLAUDE.md セクション 18 に骨格テンプレートを追加:

```markdown
### ATK-XX — [攻撃名] 🚫 BLOCKED / ⚠️ EXPOSED / ✅ BLOCKED

**Attack**: ...
**Result**: ...（BLOCKED の場合はなぜ防げたか）

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | ... | 🚫/✅/⚠️ |
...

**N BLOCKED / SAFE, M EXPOSED**
重大発見（あれば）の要約。
```

---

## 次のアクション（FT270〜）

P1〜P4 を片付けてから FT270 を開始するか、FT270 と並行して P1 だけ先に対処するか判断する。

```bash
# 未カバー FT を確認（P2 が完了するまで精度に注意）
bash tools/uncovered-fts.sh

# FT を選んだらバージョンバンプ
bash tools/bump-ft.sh 1.5.241

# CHANGELOG.md に手動追記してから
docker compose run --rm app composer check
```

---

## その他の検討事項

| 項目 | 状態 |
|------|------|
| src/ 還元 batch 2（JSON ボディ整数バリデーター等） | 📋 候補 |
| v2.0 設計検討（FT ループ摩擦点の還元） | 📋 候補 |

---

## Operating Notes

- このファイルは **5FT ごと**または**セッション終了時**に更新する。
- FT の全履歴は `docs/milestones/` と `docs/roadmap.md` に保管する。
- main がクリーンな状態でセッションを終えること。
