# FT ループ / DX Trial ワークフロー

このドキュメントは、フィールドトライアル（FT）ループと DX Trial を連続してこなすときの
専用ルール・テンプレート・ツールリファレンスをまとめたもの（旧 `CLAUDE.md` §19–21）。

> **現在このレーンは一時停止中**（`docs/todo/current.md` 参照）。再開時にこのドキュメントを起点にする。
> 中核の常時ルール（Issue 駆動・コミット規約・安全・言語ポリシー）は `CLAUDE.md` が正本。

---

## 1. FT ループ作業ルール

FT（フィールドトライアル）を連続してこなすときの専用ルール。

### セッション開始時

1. **`docs/todo/current.md` を必ず読む** — 最終 FT 番号・VERSION・次の ATK/VULN サイクルを確認する。
2. `bash tools/uncovered-fts.sh` で未カバー FT を確認し、次の対象を選ぶ。
3. 対象 FT のテストが全通過することを確認する:
   ```bash
   cd ../NENE2-FT/<name> && php8.4 vendor/bin/phpunit tests/ --no-coverage
   ```

### 各 FT の作業フロー

**ワンライナーで開始する場合（推奨）:**

```bash
# Issue 作成 → ブランチ作成 → バンプ → CHANGELOG 空エントリ挿入 を一発実行
bash tools/start-ft.sh <ft-number> <ft-name> "<howto テーマ>"
# 例: bash tools/start-ft.sh 350 workflowlog "ステートマシン型ワークフロー API"
```

**手動で行う場合:**

```
1. docs/howto/<topic>.md が既存か ls で確認 → 既存なら Write でなく Edit を使う
2. GitHub Issue 作成
3. git checkout -b docs/<issue>-ft<N>-<name>
4. bash tools/bump-ft.sh           # 引数なしで自動 +1（FrameworkInfo.php + openapi.yaml）
5. CHANGELOG.md を Read してから Edit（ブランチ切替後は必ず Read が必要）
6. docs/ft-registry.md に FT エントリが存在するか確認（new-ft.sh が自動追記済みのはず）
7. docs/howto/<topic>.md を Write または Edit。
   **先頭に YAML frontmatter（title/category/tags/difficulty 必須）を必ず付ける**
   — スキーマ: docs/development/howto-frontmatter.md
8. docker compose run --rm app composer howto:index   # README/by-tag.md を再生成（コミット対象）
9. docker compose run --rm app composer check
10. git commit / push / gh pr create / CI 待ち / merge / git pull
```

> **重要（#1331）**: CI は frontmatter を必須化（`composer howto:frontmatter -- --require-all`）し、索引の drift（`composer howto:index && git diff --exit-code`）でも fail する。frontmatter を付け忘れる／`composer howto:index` の再生成結果をコミットし忘れると **CI が落ちる**。`README.md` と `by-tag.md` は索引であり guide ではない（frontmatter 不要）。

### ATK / VULN サイクル

- **ATK**（クラッカー攻撃試験）: **4 件ごと**（FT252, 256, 260, 264, 268, 272...）
- **VULN**（脆弱性診断）: **6 件ごと**（FT249, 255, 261, 267, 273...）
- 現在のサイクル状態は `docs/todo/current.md` に記載。

### ATK / VULN howto テンプレート

ATK や VULN を書くときは以下の骨格を使う（毎回ゼロから書かなくてよい）。

#### ATK セクション骨格

```markdown
## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — [攻撃名] 🚫 BLOCKED

**Attack**: [具体的な攻撃手法の説明]
**Result**: BLOCKED — [防御できた理由、どのコードが防いだか]

---

### ATK-02 — [攻撃名] 🚫 BLOCKED

**Attack**: ...
**Result**: BLOCKED — ...

---

<!-- ATK-03 〜 ATK-12 を同形式で続ける -->

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | [攻撃名] | 🚫 BLOCKED |
| ATK-02 | [攻撃名] | 🚫 BLOCKED |
| ...  | ...  | ... |

**N BLOCKED / SAFE, 0 EXPOSED**
重大な発見があればここに要約する。なければ "No critical findings." と書く。
```

**絵文字ルール**: `🚫 BLOCKED`（防御済み）/ `⚠️ EXPOSED`（脆弱性あり）/ `✅ SAFE`（そもそも攻撃面なし）

#### VULN セクション骨格

```markdown
## Vulnerability Assessment

### V-01 — [脆弱性名] ✅ SAFE / ⚠️ EXPOSED

**Risk**: [リスクの説明]
**Finding**: SAFE / EXPOSED — [判定理由]

---

<!-- V-02 〜 V-10 を同形式で続ける -->

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | [脆弱性名] | ✅ SAFE |
| ...  | ... | ... |

**N SAFE, M EXPOSED**
重大な発見があればここに要約する。なければ "No critical findings." と書く。
```

### 引き継ぎドキュメント更新

**5FT ごと**またはセッション終了時に `docs/todo/current.md` を更新する:

```
- 最終完了 FT 番号と VERSION
- 次の FT 番号
- 次の ATK 回・VULN 回の FT 番号
- 直近 10 件の FT 履歴
- 進行中ブランチがあれば記録
```

### ツールリファレンス

```bash
bash tools/start-ft.sh 350 workflowlog "テーマ"  # FT 開始ワンライナー（推奨）
bash tools/uncovered-fts.sh              # 未カバー FT 一覧
bash tools/uncovered-fts.sh --all        # 全件（✅/❌ 表示）
bash tools/uncovered-fts.sh --check cartlog  # 特定 FT の確認
bash tools/bump-ft.sh                    # バージョン自動 +1（引数なし推奨）
bash tools/bump-ft.sh 1.5.241            # バージョン明示指定
bash tools/new-ft.sh 350 newfeaturelog   # FT プロジェクト生成（ft-registry.md 自動追記）

# howto 索引（新規・更新 howto を追加したら必ず実行）
docker compose run --rm app composer howto:index                      # README/by-tag.md を frontmatter から再生成
docker compose run --rm app composer howto:frontmatter -- --require-all # frontmatter を検証（CI と同条件）
```

---

## 2. フィールドトライアルプロジェクトでの NENE2 参照先

**重要**: このリポジトリ（`../NENE2/`）は開発版（リリース前の HEAD）。
フィールドトライアルプロジェクトからこのソースを参照するときは以下に注意すること。

| 目的 | 参照先 |
|---|---|
| 設計パターン・アーキテクチャの参考 | `../NENE2/src/` — 最新の実装例として使う |
| **実際のメソッドシグネチャ・クラス一覧** | `vendor/hideyukimori/nene2/` — インストール済みを優先 |
| 安定 API の確認 | `../NENE2/docs/adr/0009-v1.0-public-api-scope.md` |

`../NENE2/src/` に存在するクラスや引数が `vendor/` には無いことがある（リリース前の機能）。
PHPStan level 8 でキャッチできるため、静的解析を早めに実行して乖離を発見すること。

---

## 3. DX Trial ワークフロー

AI ペルソナ（A=新卒 / B=ロースキル / C=シニア）が同じ API 仕様を独立実装する DX 試験のルール。

### Trial セットアップ

```bash
# 3つのサンドボックスを自動生成（namespace は自動 PascalCase 変換）
bash tools/setup-dx-trial.sh <num> <namespace>
# 例: bash tools/setup-dx-trial.sh 27 todo  →  "Todo" に自動変換
```

生成物:
- `../NENE2-FT/dx-trial-<num>-persona-{A,B,C}/`
- 各ディレクトリに `TRIAL_LOG.md`（YAML フロントマター付き）

### TRIAL_LOG.md 更新ルール

各 Trial 完了後に **フロントマターを必ず更新** すること:

```yaml
---
trial: 27
persona: A
app: "クーポン割引"    # アプリ名
tests: 33              # phpunit のテスト数
assertions: 83         # assertion 数
phpstan: pass          # pass | fail
tx: true               # transactional() を使ったか
enum: false            # backed enum を使ったか
vo: false              # Value Object を使ったか
notes: "特記事項"       # フリクション・発見等
---
```

### 集計

```bash
bash tools/aggregate-dx-trials.sh          # 全 Trial を集計
bash tools/aggregate-dx-trials.sh 27       # Trial 27 のみ
bash tools/aggregate-dx-trials.sh 27 31    # Trial 27〜31 を集計
```

### 比較レポート作成（5 Trial ごと）

`../NENE2-FT/dx-trial-<start>-<end>-comparison-report.md` を作成する。

構成:
1. 全試験スコアボード（テスト数 / assertion 密度）
2. シナリオ別難易度分析（3ペルソナの設計比較）
3. 新たに発見されたフリクション（F-XX: ...）
4. ペルソナ別 DX 成熟度
5. 総括

フリクションは `docs/todo/dx-trial-improvements.md` に **IMP-XX** として追記する。

### DX 改善実装との連携

`docs/todo/dx-trial-improvements.md` に蓄積された IMP-XX は以下の優先度で対応:

| Phase | 対象 | 内容 |
|-------|------|------|
| 1 | フレームワーク本体 | `DatabaseConstraintException` / `DatabaseConfig::sqlite()` 等をリリース |
| 2 | `add-database-endpoint.md` | namespace 図解・AppFactory 配置・CAST パターン等を一括追記 |
| 3 | その他 howto | `use-transactions.md` / テスト howto 等に個別追記 |

### ツールリファレンス

```bash
bash tools/setup-dx-trial.sh 27 Todo       # サンドボックス生成
bash tools/aggregate-dx-trials.sh 27 31    # TRIAL_LOG.md 集計
```
